<?php
if ( ! file_exists( __DIR__ . '/.env.php'  ) ) {
	exit;
}

include __DIR__ . '/.env.php';

require_once( __DIR__ . '/vendor/autoload.php' );
require_once( __DIR__ . '/include/class-github-gardening.php' );

$gardening = new GitHubGardening( $access_token );

$gardening->notifyMergeIssuePullRequests();
$gardening->notifyUndeletedBranches();
$gardening->labelIssuesWithPullRequest();


