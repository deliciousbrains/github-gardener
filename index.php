<?php
if ( ! file_exists( __DIR__ . '/.env.php' ) ) {
	exit;
}

include __DIR__ . '/.env.php';

require_once( __DIR__ . '/vendor/autoload.php' );
require_once( __DIR__ . '/include/class-github-gardening.php' );

$gardening = new GitHubGardening( $access_token );
$gardening->setOwner( $owner );
$gardening->setRepos( $repos );
$gardening->setTeams( $teams );

$methods = array(
	'notifyMergeIssuePullRequests',
	'notifyUndeletedBranches',
	'labelIssuesWithPullRequest',
	'closeIssuesNonDefaultBranch',
	'cleanClosedPullRequestLabels',
);

$gardening->run( $methods )->fire();

