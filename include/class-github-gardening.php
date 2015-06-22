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
	protected $members;

	/**
	 * @var
	 */
	protected $last_committer;

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

		$this->setMembers();
		$this->callPullRequestMethods();
	}

	/**
	 * Get all the Pulls for a repo and for each PR run the methods
	 */
	protected function callPullRequestMethods() {
		foreach ( $this->repos as $this->repo ) {

			$this->setLastCommitter();
			$this->setBranches();

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
	 * Set the branches for a repository
	 */
	protected function setBranches() {
		$branches       = $this->client->repos->listBranches( $this->owner, $this->repo );
		$this->branches = array();

		foreach ( $branches as $branch ) {
			$this->branches[ $branch->getName() ] = $branch;
		}
	}

	/**
	 * Set the members of the teams we use for development
	 */
	protected function setMembers() {
		$teams    = $this->client->orgs->teams->listTeams( $this->owner );
		$team_ids = array();
		foreach ( $teams as $id => $team ) {
			if ( in_array( $team->getName(), array( 'On-Trial', 'Owners' ) ) ) {
				$team_ids[] = $id;
			}
		}

		$all_members = array();
		foreach ( $team_ids as $id ) {
			$members = $this->client->orgs->teams->listTeamMembers( $id );
			foreach ( $members as $member ) {
				$all_members[] = $member->getLogin();
			}
		}

		$this->members = $all_members;
	}

	/**
	 * Set the last committer
	 */
	protected function setLastCommitter() {
		$commits = $this->client->repos->commits->listCommitsOnRepository( $this->owner, $this->repo );
		$user    = $commits[0]->getAuthor();

		$this->last_committer = $user->getLogin();
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

		// Get the last updated time for the base branch
		$base = $this->pull->getBase()->getRef();

		if ( ! isset( $this->branches[ $base ]->updated ) ) {
			$branch  = $this->client->repos->getBranch( $this->owner, $this->repo, $base );
			$updated = $branch->getCommit()->getCommit()->getAuthor()->date;

			$this->branches[ $base ]->updated = $updated;
		} else {
			$updated = $this->branches[ $base ]->updated;
		}

		$updated_date = DateTime::createFromFormat( DateTime::ISO8601, $updated );
		$interval     = $updated_date->diff( new DateTime() );
		$hours        = $interval->format( '%h' );
		$minutes      = $interval->format( '%i' );

		if ( ( $hours * 60 + $minutes ) <= 10 ) {
			// if the base branch was updated in the last 10 minutes,
			// ignore in case the mergeable property is not updated yet.
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
		$comment = $this->getUserComment( 'needs develop merged in' );
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

		if ( ! isset( $this->branches[ $branch ] ) ) {
			// Branch has already been deleted
			return;
		}

		if ( false !== strpos( $branch, 'release' ) ) {
			// Don't touch release branches
			return;
		}

		if ( in_array( $branch, array( 'develop', 'master' ) ) ) {
			// Don't touch these branches
			return;
		}

		// Add comment
		$comment = $this->getUserComment( 'branch needs deleting' );
		$this->client->issues->comments->createComment( $this->owner, $this->repo, $this->pull->getNumber(), $comment );
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

		foreach ( $issues as $issue ) {
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
	 * @return string
	 */
	private function getPullRequestAuthor() {
		$user  = $this->pull->getUser();
		$login = $user->getLogin();

		if ( ! in_array( $this->members, $login ) ) {
			// The author is not a valid team member,
			// use the last committer insteas
			$login = $this->last_committer;
		}

		return $login;
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
