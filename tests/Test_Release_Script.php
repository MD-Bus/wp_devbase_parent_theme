<?php
// bin/release.sh is the only gate between a commit and every site (Warden publishes a tag the moment it is
// pushed). It is a shell script, so the suite cannot run it; what it can do is hold the ONE property that
// matters in place: the tag is never created or pushed before CI has passed on the branch. The test suite
// runs on the developer's PHP, which is newer than the theme's minimum, so CI on PHP 8.2 is the only check
// that sees the code as the oldest supported site will.
//
// (bin/ is export-ignored and never reaches a site; like the script, this test is for the repository.)

class Test_Release_Script extends WP_UnitTestCase {

	private function script() {
		$path = get_template_directory() . '/bin/release.sh';

		if ( ! is_readable( $path ) ) {
			$this->markTestSkipped( 'bin/release.sh is not part of an installed theme.' );
		}

		// Comment lines say what the code does; only the code counts here.
		return implode( "\n", array_filter( explode( "\n", (string) file_get_contents( $path ) ), function ( $line ) {
			return 0 !== strpos( ltrim( $line ), '#' );
		} ) );
	}

	/** Where in the script a piece of code first appears; fails when it does not. */
	private function position( $script, $needle ) {
		$position = strpos( $script, $needle );

		$this->assertNotFalse( $position, "bin/release.sh no longer contains: {$needle}" );

		return $position;
	}

	public function test_the_branch_is_pushed_and_ci_waited_for_before_any_tag_exists() {
		$script = $this->script();

		$push_branch = $this->position( $script, 'git push "$REMOTE" "$BRANCH"' );
		$wait        = $this->position( $script, 'STATE="$(ci_state)"' );
		$tag         = $this->position( $script, 'git tag -a "$TAG"' );
		$push_tag    = $this->position( $script, 'git push "$REMOTE" "refs/tags/$TAG"' );

		$this->assertLessThan( $wait, $push_branch, 'The branch is pushed first: a commit on main publishes nothing.' );
		$this->assertLessThan( $tag, $wait, 'CI is waited for before the tag is created.' );
		$this->assertLessThan( $push_tag, $tag );
		$this->assertSame( 1, substr_count( $script, 'git tag -a' ), 'One place creates the tag.' );
	}

	public function test_the_tag_is_never_pushed_together_with_the_branch() {
		$script = $this->script();

		// The old single push: branch and tag at once, so Warden published before CI had run at all.
		$this->assertStringNotContainsString( '--atomic', $script );
		$this->assertDoesNotMatchRegularExpression( '/git push[^\n]*\$BRANCH[^\n]*refs\/tags/', $script );
	}

	public function test_only_a_green_run_lets_the_release_through() {
		$script = $this->script();

		$this->assertMatchesRegularExpression( '/success\)\s*\n[^\n]*\n\s*break/', $script, 'success is the only state that leaves the wait.' );
		$this->assertMatchesRegularExpression( '/failed:\*\)\s*\n\s*fail /', $script, 'A red run aborts.' );
		$this->assertStringContainsString( 'CI had not finished after', $script, 'So does a run that never finishes.' );
		$this->assertStringContainsString( 'no Tests run appeared', $script, 'And one that never starts.' );
	}

	public function test_it_reads_the_run_for_this_commit_on_the_release_branch() {
		$script = $this->script();

		$this->assertStringContainsString( 'head_sha=$SHA', $script, 'The verdict must be about the commit being released, not the branch\'s last run.' );
		$this->assertStringContainsString( '"push" !== ( $run["event"]', $script, 'A pull-request run of the same commit is not the release branch\'s.' );
		$this->assertStringContainsString( 'workflows/$CI_WORKFLOW/runs', $script );
		$this->assertFileExists( get_template_directory() . '/.github/workflows/tests.yml', 'The workflow the script waits for.' );
	}

	public function test_the_workflow_it_waits_for_runs_the_minimum_php_on_a_push_to_main() {
		$workflow = get_template_directory() . '/.github/workflows/tests.yml';

		if ( ! is_readable( $workflow ) ) {
			$this->markTestSkipped( '.github/ is not part of an installed theme.' );
		}

		$yaml = (string) file_get_contents( $workflow );
		preg_match( '/^Requires PHP:\s*(\S+)/m', (string) file_get_contents( get_template_directory() . '/style.css' ), $m );

		$this->assertMatchesRegularExpression( '/push:\s*\n\s*branches:\s*\[main\]/', $yaml, 'Without a run on a push to main the script would wait for nothing.' );
		$this->assertMatchesRegularExpression( '/php:\s*\[[^\]]*"' . preg_quote( $m[1], '/' ) . '"/', $yaml, 'CI must test the PHP version style.css promises (' . $m[1] . ').' );
	}

	public function test_skipping_ci_is_possible_only_on_purpose_and_says_so() {
		$script = $this->script();

		$this->assertStringContainsString( '"${RELEASE_SKIP_CI:-0}" = "1"', $script, 'Off unless asked for.' );
		$this->assertStringContainsString( 'WARNING: RELEASE_SKIP_CI=1', $script );
	}

	public function test_the_script_is_valid_shell() {
		exec( 'bash -n ' . escapeshellarg( get_template_directory() . '/bin/release.sh' ) . ' 2>&1', $output, $code );

		$this->assertSame( 0, $code, implode( "\n", $output ) );
	}
}
