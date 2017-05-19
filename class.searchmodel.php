<?php defined('APPLICATION') or die;

class SearchModel extends Gdn_Model {

    // List of content types that are handled by this model.
    public $contentTypes = array('Discussion', 'Comment');

    // Array of stopwords.
    private $_stopWords;

    /**
     * Get stopwords from locale definitions file.
     *
     * @return void
     * @since 0.1
     * @package Find
     */
    public function __construct () {
         // import stopwords from /plugins/find/locale/xx_stopwords.php
        include_once('locale'.DS.Gdn::locale()->Locale.'_stopwords.php');
        $this->_stopWords = $stopwords;

        parent::__construct();
    }

    /**
     * Index content.
     *
     * Text is simplified so that only alphanumeric strings are stored in db.
     *
     * @param integer $contentID ID of content to index.
     * @param string $contentType Either Discussion or Comment.
     * @since 0.2
     * @package Find
     */
    public function addOrUpdate($contentID, $contentType) {
        // Sanitize Input;
        if (!isset($contentID)) {
            return false;
        }
        if (!isset($contentType) || !in_array($contentType, $this->contentTypes)) {
            return false;
        }
        $contentID = (int)$contentID;

        // As long as we only use Discussion and Comment, that shouldn't fail...
        $contentModelName = $contentType.'Model';
        if (!class_exists($contentModelName)) {
            return false;
        }

        // Create Model and get text from db.
        $contentModel = new $contentModelName();
        $content = $contentModel->getId($contentID);
        $contentText = Gdn_Format::to($content->Body, $content->Format);

        if (isset($content->Name)) {
            $contentText = $content->Name.' '.$contentText;
        }

        // Replace all non-alphanumeric characters with space.
        $wordList = preg_replace(
            '/([^\w]|_|\b(\w{0,'.(c('Find.MinWordLength', '4') - 1).'}|\w{60,})\b)/u',
            ' ',
            strtolower($contentText)
        );

        // Create an array from text and strip out all repetitions. And get
        // word count.
        $wordList = array_count_values(
            array_diff(
                array_filter(explode(' ', $wordList)),
                $this->_stopWords
            )
        );

        // Add search words to word list
        $findWordListInsertData = array();
        foreach ($wordList as $word => $count) {
            $findWordListInsertData[] = array('Word' => $word);
        }

        Gdn::sql()
            ->options('Ignore', true)
            ->insert('FindWordList', $findWordListInsertData);

        // Get them back
        $wordIndex = Gdn::sql()->get('FindWordList')->resultArray();
        $wordIndex = array_column($wordIndex, 'WordID', 'Word');

        $findIndexInsertData = array();
        foreach ($wordList as $word => $count) {
            $findIndexInsertData[] = array(
                'WordID' => $wordIndex[$word],
                'ContentType' => $contentType,
                'ContentID' => $contentID,
                'WordCount' => $count
            );
        }
        // First delete all existing content.
        $this->delete($contentType, $contentID);

        // Then insert new data.
        Gdn::sql()->insert('FindIndex', $findIndexInsertData);
        return true;
    }

    /**
     * Deletes data for specified content from index.
     *
     * @param integer $contentID ID of the content to delete.
     * @param string $contentType Either Discussion or Comment.
     * @return boolean True on success, false on fail.
     * @package Find
     * @since 0.2
     */
    public function delete($contentID, $contentType) {
        // Sanitize Input;
        if (!isset($contentID)) {
            return false;
        }
        if (!isset($contentType) || !in_array($contentType, $this->contentTypes)) {
            return false;
        }

        $sql = Gdn::sql()->delete('FindIndex', array(
            'ContentType' => $contentType,
            'ContentID' => (int)$contentID
        ));
        return true;
    }




    // TODO: implement +word, -word, *word*


    /**
     * Search for search terms in index table.
     *
     * Search can be influenced by c('Find.CompleteWords'). If set to false,
     * words are compared with LIKE %word%.
     *
     * @param string $search Search term.
     * @param integer $offset For pagination.
     * @param integer $limit For pagination.
     * @return mixed Array with various informationfrom found Discussions/Comments.
     * @package Find
     * @since 0.2
     */
    public function search($search, $offset = 0, $limit = 20) {
        if (!$search) {
            return array();
        }

        // Convert search term to sorted array.
        $searchTerms = array_filter(explode(' ', $search));
        sort($searchTerms);

        // Only do db stuff if that search is not cached!
        $cacheKey = 'find.'.implode('.', $searchTerms);
        $result = Gdn::cache()->get($cacheKey);

        if (!$result) {
// decho('Uncached :-(');
            if (Gdn::config('Find.CompleteWords') != true) {
                // "Wildcar*" match.
                foreach($searchTerms as $term) {
                    Gdn::sql()->like('fwl.Word', $term, 'both');
                }
            } else {
                // Exact match.
                Gdn::sql()->whereIn('fwl.Word', $searchTerms);
            }

            $searchResults = Gdn::sql()
                ->select('fi.WordID, fi.ContentType, fi.ContentID, fi.WordCount, fwl.Word')
                ->from('FindWordList fwl')
                ->join('FindIndex fi', 'fwl.WordID = fi.WordID')
                ->get()
                ->resultArray();

            if (count($searchResults) === 0) {
                return array();
            }

            // Build one sql to get all needed information at once.
            $searchSql = '';
            // Respect category permissions
            $categories = CategoryModel::CategoryWatch();
            foreach ($searchResults as $result) {
                if ($searchSql !== '') {
                    $searchSql .= "\r\n union \r\n";
                }
                // Select fields that are common for each content type.
                Gdn::sql()
                    ->select("'{$result['Word']}' as Word")
                    ->select("'{$result['WordCount']}'")
                    ->select("'{$result['ContentType']}' as RecordType")
                    ->select("'{$result['ContentID']}' as PrimaryID")
                    ->select("'{$result['ContentType']}_{$result['ContentID']}' as UniqueID")
                    ->select('c.Body, c.Format, c.DiscussionID, c.DateInserted, c.Score, c.InsertUserID as UserID')
                    ->select('u.Name')
                    ->from("{$result['ContentType']} c")
                    ->join('User u', 'c.InsertUserID = u.UserID')
                    ->where("{$result['ContentType']}ID", $result['ContentID'])
                    ->whereIn('cat.CategoryID', $categories);

                // Add content type specific columns
                if ($result['ContentType'] === 'Discussion') {
                    Gdn::sql()
                        ->select("'/discussion/{$result['ContentID']}' as Url")
                        ->select('c.Name as Title, c.CategoryID, c.Tags')
                        ->select("'{$result['ContentID']}' as DiscussionID")
                        ->select('cat.Name as Category, cat.UrlCode as CategoryUrl')
                        ->join('Category cat', 'c.CategoryID = cat.CategoryID');
                } elseif ($result['ContentType'] === 'Comment') {
                    Gdn::sql()
                        ->select("'/discussion/comment/{$result['ContentID']}/#Comment_{$result['ContentID']}' as Url")
                        ->select('d.Name as Title, d.CategoryID, d.Tags')
                        ->select('d.DiscussionID')
                        ->select('cat.Name as Category, cat.UrlCode as CategoryUrl')
                        ->join('Discussion d', 'c.DiscussionID = d.DiscussionID')
                        ->join('Category cat', 'd.CategoryID = cat.CategoryID');
                }

                // TODO: think about (and get feedback from community) about hook.
                // Is this the best place? Sql could be mingled with best if it is
                // called like that, but it will be called in a loop!
                // Gdn::controller()->EventArguments['Select'] = &Gdn::sql();
                // Gdn::controller()->fireEvent('AfterBuildSearchQuery');

                // Get sql as string with all named parameters already set.
                $searchSql .= Gdn::sql()->applyParameters(
                    Gdn::sql()->getSelect(),
                    array( ':'.$result['ContentType'].'ID' => $result['ContentID'])
                );
                Gdn::sql()->reset();
            }
            $searchSql = "SELECT * FROM ({$searchSql}) SearchResults ORDER BY DateInserted DESC";

            // Query db with our prebuild sql.
            $result = Gdn::sql()->query($searchSql)->resultArray();

            // Sort results by DateInserted.
            array_multisort(
                array_column($result, 'DateInserted'),
                SORT_DESC,
                SORT_NATURAL,
                $result
            );

            // Store in cache with find.word(.word.word...)
            Gdn::cache()->store($cacheKey, $result, array(Gdn_Cache::FEATURE_EXPIRY => 300));
        }

        // Save record count in session for use in pagination.
        Gdn::session()->stash('Find.RecordCount', count($result));

        // Reduce search results to "one page" only.
        $result = array_slice($result, $offset, $limit);

        // Loop through results to build Summary.
        foreach ($result as $key => $value) {
            // TODO: think about "relavence" ;)
            // By now it will use insert date.
            if (isset($value['Body'])) {
                $result[$key]['Summary'] = condense(
                    Gdn_Format::to(
                        $value['Body'],
                        $value['Format']
                    )
                );
            }
        }

        return $result;
    }
}
