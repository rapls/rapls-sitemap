<?php
/**
 * The build and translation scripts in `bin/`.
 *
 * These run by hand, not at runtime, which is exactly why they need covering:
 * every way they can go wrong is quiet. A string the extractor misses shows up
 * in English on somebody else's site; a file the build script fails to exclude
 * ships to WordPress.org. Neither produces an error anywhere.
 *
 * The extractor is pointed at a fixture rather than at the plugin, so the
 * assertions can be about behaviour — including the cases it is supposed to
 * *reject* — instead of about whatever the plugin happens to contain today.
 *
 *   php tests/smoke-tooling.php
 *
 * @package RaplsSitemap
 */

// phpcs:disable

require_once __DIR__ . '/lib/bootstrap.php';

$root = dirname( __DIR__ );
$tmp  = rtrim( (string) ( getenv( 'TMPDIR' ) ?: sys_get_temp_dir() ), '/' ) . '/rapls-tooling-' . getmypid();

/**
 * Remove a directory tree.
 */
function scrub( $path ) {
	if ( ! is_dir( $path ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $path );
}

scrub( $tmp );
mkdir( $tmp . '/src/Sub', 0777, true );
mkdir( $tmp . '/assets/js', 0777, true );
mkdir( $tmp . '/blocks/thing', 0777, true );
mkdir( $tmp . '/languages', 0777, true );

/* --- a fixture built out of the cases that actually go wrong ------------- */

file_put_contents(
	$tmp . '/src/Sub/Fixture.php',
	'<?php
namespace Fixture;

class Thing {
	public function run() {
		__( "Plain string", "fixture" );
		esc_html__( "Escaped string", "fixture" );

		/* translators: %s: a name. */
		printf( __( "Hello %s", "fixture" ), $name );

		__( "Belongs to somebody else", "other-plugin" );
		__( "No domain at all" );

		$this->__( "A method that happens to be named like one", "fixture" );
		Other::__( "A static call that happens to be named like one", "fixture" );

		__( "Split " . "across concatenation", "fixture" );

		$variable = "Not a literal";
		__( $variable, "fixture" );
	}
}
'
);

file_put_contents(
	$tmp . '/assets/js/thing.js',
	"wp.i18n.__( 'From JavaScript', 'fixture' );\n" .
	"__( 'Also from JavaScript', 'fixture' );\n" .
	"__( 'JavaScript, wrong domain', 'other-plugin' );\n"
);

file_put_contents(
	$tmp . '/blocks/thing/block.json',
	json_encode(
		array(
			'name'        => 'fixture/thing',
			'title'       => 'Block title',
			'description' => 'Block description',
			'keywords'    => array( 'first keyword' ),
		)
	)
);

$output = (string) shell_exec(
	sprintf( 'php %s %s %s 2>&1', escapeshellarg( $root . '/bin/make-pot.php' ), escapeshellarg( $tmp ), escapeshellarg( 'fixture' ) )
);

$pot = (string) @file_get_contents( $tmp . '/languages/fixture.pot' );

check( '' !== $pot, 'the extractor writes a POT where it is told to', $output );

/**
 * Is this exact msgid in the catalogue?
 */
function has_msgid( $pot, $text ) {
	return false !== strpos( $pot, "\nmsgid \"" . $text . "\"\n" );
}

/* --- what it must find --------------------------------------------------- */

check( has_msgid( $pot, 'Plain string' ), 'a plain __() call is found' );
check( has_msgid( $pot, 'Escaped string' ), 'so is esc_html__()' );
check( has_msgid( $pot, 'Hello %s' ), 'and one wrapped in printf' );
check( has_msgid( $pot, 'Split across concatenation' ), 'a concatenated literal is joined rather than dropped' );
check( has_msgid( $pot, 'From JavaScript' ), 'a wp.i18n.__() call is found' );
check( has_msgid( $pot, 'Also from JavaScript' ), 'and a bare __() in JavaScript' );
check( has_msgid( $pot, 'Block title' ), "block.json's title is found, though no __() names it" );
check( has_msgid( $pot, 'Block description' ), 'and its description' );
check( has_msgid( $pot, 'first keyword' ), 'and its keywords' );

check( false !== strpos( $pot, '#. translators: %s: a name.' ), 'a translators comment travels with its string' );
check( false !== strpos( $pot, 'src/Sub/Fixture.php:' ), 'entries carry a file reference' );

/* --- what it must refuse ------------------------------------------------- */

check( ! has_msgid( $pot, 'Belongs to somebody else' ), "another plugin's domain is not borrowed" );
check( ! has_msgid( $pot, 'JavaScript, wrong domain' ), 'in JavaScript either' );
check( ! has_msgid( $pot, 'No domain at all' ), 'and a call with no domain is left alone' );

// The tokenizer is the reason these are distinguishable at all: a regex would
// have taken the method and the static call for translator functions.
check( ! has_msgid( $pot, 'A method that happens to be named like one' ), 'a method call named __ is not a translator call' );
check( ! has_msgid( $pot, 'A static call that happens to be named like one' ), 'nor is a static one' );

check( ! has_msgid( $pot, 'Not a literal' ), 'and a variable argument is skipped rather than guessed at' );

/* --- the build script ---------------------------------------------------- */

$dist = $tmp . '/dist';
$build = (string) shell_exec( sprintf( 'bash %s %s 2>&1', escapeshellarg( $root . '/bin/build.sh' ), escapeshellarg( $dist ) ) );
$zip   = $dist . '/rapls-sitemap.zip';

check( file_exists( $zip ), 'the build script writes a ZIP where it is told to', $build );

if ( file_exists( $zip ) ) {
	$listing = (string) shell_exec( sprintf( 'unzip -Z1 %s 2>&1', escapeshellarg( $zip ) ) );
	$paths   = array_filter( array_map( 'trim', explode( "\n", $listing ) ) );

	// WordPress.org unpacks the archive into wp-content/plugins, so everything
	// has to sit under one folder named for the slug.
	$stray = array();
	foreach ( $paths as $path ) {
		if ( 0 !== strpos( $path, 'rapls-sitemap/' ) ) {
			$stray[] = $path;
		}
	}
	check( array() === $stray, 'everything unpacks into a single rapls-sitemap/ folder', implode( ', ', array_slice( $stray, 0, 3 ) ) );

	$needed = array( 'rapls-sitemap/rapls-sitemap.php', 'rapls-sitemap/uninstall.php', 'rapls-sitemap/readme.txt', 'rapls-sitemap/LICENSE' );
	$absent = array();
	foreach ( $needed as $file ) {
		if ( ! in_array( $file, $paths, true ) ) {
			$absent[] = $file;
		}
	}
	check( array() === $absent, 'the files WordPress needs are all in it', implode( ', ', $absent ) );

	foreach ( array( 'src/', 'assets/', 'blocks/', 'languages/' ) as $dir ) {
		$found = false;
		foreach ( $paths as $path ) {
			if ( 0 === strpos( $path, 'rapls-sitemap/' . $dir ) ) {
				$found = true;
				break;
			}
		}
		check( $found, sprintf( '%s ships', $dir ) );
	}

	// .distignore exists to keep these out; a build that quietly stopped
	// honouring it would publish the test suite and the assistant's notes.
	$leaked = array();
	foreach ( array( 'tests/', 'bin/', '.git', '.claude', 'CLAUDE.md', 'composer.json', '.distignore' ) as $excluded ) {
		foreach ( $paths as $path ) {
			if ( false !== strpos( $path, '/' . $excluded ) ) {
				$leaked[] = $excluded;
				break;
			}
		}
	}
	check( array() === $leaked, 'and nothing .distignore excludes gets in', implode( ', ', $leaked ) );
}

scrub( $tmp );

summary();
