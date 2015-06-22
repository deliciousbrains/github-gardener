<?php
if ( ! file_exists( __DIR__ . '/.env.php' ) ) {
	exit;
}

include __DIR__ . '/.env.php';

require_once( __DIR__ . '/vendor/autoload.php' );
require_once( __DIR__ . '/include/class-github-gardening.php' );


$owner = 'deliciousbrains';
$repos = array(
	'wp-aws',
	'wp-migrate-db-pro'
);

$gardening = new GitHubGardening( $access_token, $owner, $repos );

$methods = array(
	'notifyMergeIssuePullRequests',
	'notifyUndeletedBranches',
	'labelIssuesWithPullRequest',
	'closeIssuesNonDefaultBranch'
);

$gardening->run( $methods )->fire();

