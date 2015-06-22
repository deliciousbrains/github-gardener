<?php

/**
 * Class GitHubGardening
 *
 * @package     github-gardener
 * @copyright   Copyright (c) 2015, Delicious Brains
 * @author      Iain Poulson
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 */
class GitHubGardening {

	/**
	 * @var GitHubClient
	 */
	protected $client;

	/**
	 * @var string
	 */
	protected $owner;

	/**
	 * @var array
	 */
	protected $repos;

	/**
	 * @var array
	 */
	protected $repo;

	/**
	 * @var
	 */
	protected $pull;

	/**
	 * @var
	 */
	protected $methods;

	/**
	 * @var
	 */
	protected $branches;

	/**
	 * @var
	 */
	protected $branch_names;

	const MASTER_BRANCH = 'develop';

	/**
	 * GitHubGardening constructor.
	 *
	 * @param string|null  $token
	 * @param string       $owner
	 * @param array|string $repos
	 *
	 * @throws GitHubClientException
	 */
	public function __construct( $token = null, $owner, $repos ) {
		$this->client = new GitHubClient();
		$this->client->setAuthType( 'x-oauth-basic' );
		$this->client->setOauthKey( $token );

		$this->owner = $owner;
		if ( is_string( $repos ) ) {
			$repos = array( $repos );
		}
		$this->repos = $repos;
	}

	/**
	 * Define the methods to be run
	 *
	 * @param $methods
	 *
	 * @return $this
	 */
	public function run( $methods ) {
		$this->methods = $methods;

		return $this;
	}

	/**
	 * Fire it up
	 */
	public function fire() {
		if ( is_null( $this->methods ) ) {
			return;
		}

		$this->callPullRequestMethods();
	}

	/**
	 * Get all the Pulls for a repo and for each PR run the methods
	 */
	protected function callPullRequestMethods() {
		foreach ( $this->repos as $this->repo ) {

			$this->getBranches();

			$all_pulls = array();
			$this->client->setPageSize( 100 );
			$page = 1;
			while ( $page > 0 ) {
				$this->client->setPage( $page );
				$pulls = $this->client->pulls->listPullRequests( $this->owner, $this->repo, 'all' );
				if ( ! empty( $pulls ) ) {
					$page ++;
					$all_pulls = array_merge( $all_pulls, $pulls );
				} else {
					break;
				}
			}

			foreach ( $all_pulls as $this->pull ) {

				foreach ( $this->methods as $method ) {

					if ( in_array( $method, array( 'run', 'fire' ) ) ) {
						// Ignore public core methods
						continue;
					}

					if ( ! method_exists( $this, $method ) ) {
						// Ignore methods that don't exist
						continue;
					}

					// Run the method
					call_user_func( array( $this, $method ) );
				}
			}
		}
	}

	/**
	 * Get the branches for a repository and store them in an array and one just for names
	 */
	protected function getBranches() {
		$this->branches = $this->client->repos->listBranches( $this->owner, $this->repo );

		$names = array();
		foreach ( $this->branches as $branch ) {
			$names[] = $branch->getName();
		}

		$this->branch_names = $names;
	}

	/**
	 * Removes redundant labels from closed PRs
	 *
	 * @scope closed
	 */
	public function cleanClosedPullRequestLabels() {
		if ( 'closed' !== $this->pull->getState() ) {
			return;
		}

		$id     = $this->pull->getNumber();
		$labels = $this->client->issues->labels->listLabelsOnAnIssue( $this->owner, $this->repo, $id );

		$process_replacement = false;
		$redundant_labels    = array( 'ready for review', 'needs merge' );
		$labels_to_remove    = array();
		foreach ( $labels as $label ) {
			if ( in_array( $label->getName(), $redundant_labels ) ) {
				$labels_to_remove[]  = $label->getName();
				$process_replacement = true;
			}
		}

		if ( ! $process_replacement ) {
			return;
		}

		// Remove redundant labels
		foreach ( $labels_to_remove as $label ) {
			// TODO
			#$this->client->issues->labels->removeLabelFromAnIssue( $this->owner, $this->repo, $id, $label );
		}
	}

	/**
	 * Check for open PRs ready for review that are not mergeable
	 *
	 * @scope open
	 */
	public function notifyMergeIssuePullRequests() {
		if ( 'open' !== $this->pull->getState() ) {
			return;
		}

		$id               = $this->pull->getNumber();
		$labels           = $this->client->issues->labels->listLabelsOnAnIssue( $this->owner, $this->repo, $id );
		$ready_for_review = false;
		$label_exists     = false;
		foreach ( $labels as $label ) {
			if ( 'ready for review' == $label->getName() ) {
				$ready_for_review = true;
			}

			if ( 'needs merge' == $label->getName() ) {
				$label_exists = true;
			}
		}

		if ( ! $ready_for_review ) {
			return;
		}

		$pull_request = $this->client->pulls->getSinglePullRequest( $this->owner, $this->repo, $id );

		if ( $pull_request->isMergeable() ) {
			if ( $label_exists ) {
				// TODO remove label
			}

			return;
		}

		if ( $label_exists ) {
			// Already got the label
			return;
		}

		// Add Label
		$label = 'needs merge';
		$this->client->issues->labels->addLabelsToAnIssue( $this->owner, $this->repo, $id, $label );

		// Add comment
		$comment = $this->getUserComment( $pull_request, 'needs develop merged in' );
		// @username Needs develop merged in
		$this->client->issues->comments->createComment( $this->owner, $this->repo, $id, $comment );
	}

	/**
	 * Notify authors of closed PRs that have not had their branch deleted.
	 * This ignores release branches.
	 *
	 * @scope closed
	 */
	public function notifyUndeletedBranches() {
		if ( 'closed' !== $this->pull->getState() ) {
			return;
		}

		$branch = $this->pull->getHead()->getRef();

		if ( in_array( $branch, $this->branch_names ) && false === strpos( $branch, 'release' ) ) {
			// Add comment
			$comment = $this->getUserComment( $this->pull, 'branch needs deleting' );
			$this->client->issues->comments->createComment( $this->owner, $this->repo, $this->pull->getNumber(), $comment );
		}
	}

	/**
	 * For all open PRs with referenced issues (via 'Resolves #123'), add the label 'has PR' to the issues
	 * if the label doesn't exist.
	 *
	 * @scope open
	 */
	public function labelIssuesWithPullRequest() {
		if ( 'open' !== $this->pull->getState() ) {
			return;
		}

		$issues = $this->getPullRequestIssues();

		foreach( $issues as $issue ) {
			$labels       = $this->client->issues->labels->listLabelsOnAnIssue( $this->owner, $this->repo, $issue );
			$new_label    = 'has PR';
			$label_exists = false;
			foreach ( $labels as $label ) {
				if ( $new_label === $label->getName() ) {
					$label_exists = true;
				}
			}

			if ( $label_exists ) {
				// Already got the label
				continue;
			}

			// Add Label
			$this->client->issues->labels->addLabelsToAnIssue( $this->owner, $this->repo, $issue, $new_label );
		}
	}

	/**
	 * Close issues that have been resolved by PRs merged into a non default branch
	 *
	 * @scope closed
	 */
	public function closeIssuesNonDefaultBranch() {
		if ( 'closed' !== $this->pull->getState() ) {
			return;
		}

		$pull_base_branch = $this->pull->getBase()->getRef();

		if ( $pull_base_branch === self::MASTER_BRANCH ) {
			// Pulled against default branch, ignore
			return;
		}

		$issues = $this->getPullRequestIssues();

		foreach ( $issues as $issue_id ) {
			$issue = $this->client->issues->getIssue( $this->owner, $this->repo, $issue_id );

			if ( 'closed' === $issue->getState() ) {
				continue;
			}

			// Close the issue
			$this->client->issues->editAnIssue( $this->owner, $this->repo, $issue->getTitle(), $issue_id, null, null, 'closed' );

			// Add comment
			$comment = $this->getComment( 'Closed as PR merged' );
			$this->client->issues->comments->createComment( $this->owner, $this->repo, $issue_id, $comment );
		}
	}

	/**
	 * Get the issue IDs associated with a Pull with the 'resolves #' text
	 *
	 * @return array
	 */
	private function getPullRequestIssues() {
		$body = $this->pull->getBody();

		// Find any issues from the body of the PR
		preg_match_all( '/resolves #\s*(\d+)/i', $body, $matches );

		$issues = array();

		if ( isset( $matches[1] ) ) {
			$issues = $matches[1];
		}

		return $issues;
	}

	/**
	 * Get the author of the pull request
	 *
	 * @param int|GitHubFullPull $pull
	 * @param string             $repo
	 *
	 * @return string
	 */
	private function getPullRequestAuthor( $pull, $repo = '' ) {
		if ( ! is_object( $pull ) ) {
			$pull = $this->client->pulls->getSinglePullRequest( $this->owner, $repo, $pull );
		}

		$user = $pull->getUser();

		// TODO check the author is still a valid team member
		// TODO else use last committer on repo

		return $user->getLogin();
	}

	/**
	 * Get the comment to be added to a Pull
	 *
	 * @param int|GitHubFullPull $pull
	 * @param string             $text
	 *
	 * @return string
	 */
	private function getUserComment( $pull, $text ) {
		$comment = $this->getComment( $text );
		$comment = '@' . $this->getPullRequestAuthor( $pull ) . ' ' . $comment;

		return $comment;
	}

	/**
	 * Create comment
	 *
	 * @param string $text
	 *
	 * @return string
	 */
	private function getComment( $text ) {
		return $text . ' [gardening]';
	}
}
