<?php defined('APPLICATION') or die;
$configurationModule = $this->configurationModule;

echo '<h1>', $this->title(), '</h1>';

$configurationModule->render();

if ($this->data('unindexedCommentCount') > 0 || $this->data('unindexedDiscussionCount') > 0) {
    ?>
    <hr />
    <div class="Warning">There are <?= $this->data('unindexedCommentCount') ?> unindexed comments and <?= $this->data('unindexedDiscussionCount') ?> unindexed discussions. You have to start the indexing manually for those contents.<br /><strong>Please refresh this page if you get a timeout to see if there are still contents to index!</strong></div>
    <div class="Buttons">
        <?= anchor('Index Comments', 'plugin/find/Comment', array('class' => 'Hijack Button')) ?>
        <?= anchor('Index Discussions', 'plugin/find/Discussion', array('class' => 'Hijack Button')) ?>
    </div>

    <?php
}


