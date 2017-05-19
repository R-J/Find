<?php defined('APPLICATION') or die;

$PluginInfo['Find'] = array(
    'Name' => 'Find',
    'Description' => 'Find is a replacement for Vanillas search function.',
    'Version' => '0.2',
    'RequiredApplications' => array('Vanilla' => '2.1'),
    'HasLocale' => true,
    'MobileFriendly' => true,
    'Author' => 'Robin Jurinka',
    'AuthorUrl' => 'http://vanillaforums.org/profile/44046/R_J',
    'SettingsUrl' => '/dashboard/settings/find',
    'SettingsPermission' => 'Garden.Settings.Manage',
    'License' => 'MIT'
);

/**
 * Find is a replacement for Vanillas search function.
 *
 * @package Find
 * @author Robin Jurinka
 * @license MIT
 */
class FindPlugin extends Gdn_Plugin {
    /**
     * Setup is called when plugin is enabled and prepares config and db.
     *
     * @package Find
     * @since 0.1
     */
    public function setup() {
        // Init some config settings.
        if (!c('Find.MinWordLength')) {
            saveToConfig('Find.MinWordLength', 4);
        }
        if (!c('Find.CompleteWords')) {
            saveToConfig('Find.CompleteWords', true);
        }
        // set up tables
        $this->structure();
    }

    /**
     * Structure is called by setup() and adds tables to db.
     *
     * @package Find
     * @since 0.1
     */
    public function structure($explicit = false, $drop = false) {
        Gdn::database()->ExtendedProperties['Collate'] ='utf8_bin';
        // Wikipedia: "The 45-letter word pneumonoultramicroscopicsilicovolcanoconiosis is the longest English word that appears in a major dictionary.", so varchar 64 should be enough
        Gdn::database()->structure()
            ->table('FindWordList')
            ->primaryKey('WordID')
            ->column('Word', 'varchar(64)', false, 'unique')
            ->engine('InnoDB')
            ->set($explicit, $drop);

        Gdn::database()->structure()
            ->table('FindIndex')
            ->primaryKey('FindID')
            ->column('WordID', 'int(11)', false, 'index')
            ->column('ContentType', 'varchar(20)', false) // Discussion/Comment
            ->column('ContentID', 'int(11)', false) // DiscussionID/CommentID
            ->column('WordCount', 'int(11)', false)
            ->engine('InnoDB')
            ->set($explicit, $drop);
    }

    /**
     * Create settings screen and gives opportunity to index unindexed content.
     *
     * @package Find
     * @since 0.2
     */
    public function settingsController_find_create($sender) {
        $sender->permission('Garden.Settings.Manage');
        $sender->addSideMenu('dashboard/settings/plugins');

        $sender->title(t('Find! Settings'));

        $prefix = Gdn::database()->DatabasePrefix;
        $subSelect = "(SELECT ContentID FROM {$prefix}FindIndex WHERE ContentType = 'Discussion')";
        $sql = "SELECT COUNT(DiscussionID) FROM {$prefix}Discussion WHERE DiscussionID NOT IN {$subSelect}";
        $unindexedDiscussionCount = Gdn::sql()->query($sql)->resultArray();
        $sender->setData('unindexedDiscussionCount', $unindexedDiscussionCount[0]['COUNT(DiscussionID)']);
        $subSelect = "(SELECT ContentID FROM {$prefix}FindIndex WHERE ContentType = 'Comment')";
        $sql = "SELECT COUNT(CommentID) FROM {$prefix}Comment WHERE CommentID NOT IN {$subSelect}";
        $unindexedCommentCount = Gdn::sql()->query($sql)->resultArray();
        $sender->setData('unindexedCommentCount', $unindexedCommentCount[0]['COUNT(CommentID)']);

        $configurationModule = new configurationModule($sender);
        $configurationModule->initialize(
            array(
                'Find.MinWordLength' => array(
                    'Control' => 'TextBox',
                    'LabelCode' => 'Minimum Length',
                    'Description' => 'Words with less than that letters will not be indexed.',
                    'Default' => '4',
                    'Options' => array('type' => 'number')
                ),
                'Find.CompleteWords' => array(
                    'Control' => 'CheckBox',
                    'LabelCode' => 'Find Only Complete Words',
                    'Description' => 'Uncheck this if you also want to find "<q>redun<strong>dance</strong></q>" when you search for "<q>dance</q>".',
                    'Default' => 'false'
                )
            )
        );
        $sender->configurationModule = $configurationModule;
        $sender->render('Settings', '', 'plugins/find');
    }


    /**
     * Call function to save discussion content to index.
     *
     * @param object $sender DiscussionModel.
     * @param object $args EventArguments.
     * @package Find
     * @since 0.2
     */
    public function discussionController_afterSaveDiscussion_handler($sender, $args) {
        $searchModel = new SearchModel();
        $searchModel->addOrUpdate(
            $args['DiscussionID'],
            'Discussion'
        );
    }

    /**
     * Call function to save comment content to index.
     *
     * @param object $sender CommentModel.
     * @param mixed $args EventArguments.
     * @package Find
     * @since 0.2
     */
    public function commentModel_afterSaveComment_handler($sender, $args) {
        $searchModel = new SearchModel();
        $searchModel->addOrUpdate(
            $args['CommentID'],
            'Comment'
        );
    }

    /**
     * Call function to purge discussions information from index.
     *
     * @param object $sender DiscussionModel.
     * @param mixed $args EventArguments.
     * @package Find
     * @since 0.2
     */
    public function discussionModel_deleteDiscussion_handler($sender, $args) {
        $searchModel = new SearchModel();
        $searchModel->delete(
            $args['DiscussionID'],
            'Discussion'
        );
    }

    /**
     * Call function to purge comments information from index.
     *
     * @param object $sender CommentModel.
     * @param mixed $args EventArguments.
     * @package Find
     * @since 0.2
     */
    public function commentModel_deleteComment_handler($sender, $args) {
        $searchModel = new SearchModel();
        $searchModel->delete(
            $args['CommentID'],
            'Comment'
        );
    }

    /**
     * Indexes discussions and comments that are not indexed by now.
     *
     * @param object $sender PluginConroller.
     * @param mixed $args ContentType: either Discussion or Comment.
     * @package Find
     * @since 0.2
     */
    public function pluginController_find_create ($sender, $args) {
        $sender->permission('Garden.Settings.Manage');
        $prefix = Gdn::database()->DatabasePrefix;
        switch ($args[0]) {
            case 'Discussion':
                $subSelect = "(SELECT ContentID FROM {$prefix}FindIndex WHERE ContentType = 'Discussion')";
                $sql = "SELECT DiscussionID FROM {$prefix}Discussion WHERE DiscussionID NOT IN {$subSelect}";
                $ids = array_column(Gdn::sql()->query($sql)->resultArray(), 'DiscussionID');
                break;
            case 'Comment':
                $subSelect = "(SELECT ContentID FROM {$prefix}FindIndex WHERE ContentType = 'Comment')";
                $sql = "SELECT CommentID FROM {$prefix}Comment WHERE CommentID NOT IN {$subSelect}";
                $ids = array_column(Gdn::sql()->query($sql)->resultArray(), 'CommentID');
                break;
            default:
                return false;
        }

        $searchModel = new SearchModel();
        foreach ($ids as $id) {
            $searchModel->addOrUpdate($id, $args[0]);
        }
        return true;
    }

    /**
     * Add result count to searchcontroller so that results view can use it.
     * @param object $sender SearchController.
     * @package Find
     * @since 0.2
     */
    public function searchController_render_before($sender, $args) {
        $sender->setData('RecordCount', Gdn::session()->stash('Find.RecordCount'));
    }
}
