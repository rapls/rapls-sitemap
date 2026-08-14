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

	foreach ( array( 'src/', 'assets/', 'blocks/' ) as $dir ) {
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
	// `languages/` is on this list, not the one above. A WordPress.org-hosted
	// plugin gets its catalogue from translate.wordpress.org, and a bundled
	// copy is never loaded — nothing calls load_plugin_textdomain() any more,
	// so shipping one would be dead weight that looks editable.
	$leaked = array();
	foreach ( array( 'tests/', 'bin/', '.git', '.claude', 'CLAUDE.md', 'composer.json', '.distignore', 'languages/' ) as $excluded ) {
		foreach ( $paths as $path ) {
			if ( false !== strpos( $path, '/' . $excluded ) ) {
				$leaked[] = $excluded;
				break;
			}
		}
	}
	check( array() === $leaked, 'and nothing .distignore excludes gets in', implode( ', ', $leaked ) );
}

/* --- the metadata WordPress.org reads at submission ---------------------- */

/*
 * The one mistake on this screen that cannot be undone.
 *
 * WordPress.org derives the plugin's slug from the `Plugin Name` header, and
 * that slug is frozen the moment the plugin is approved. The text domain has to
 * equal it, or translate.wordpress.org never serves the catalogue and Plugin
 * Check reports a mismatch on every translated string in the plugin. That is
 * not a hypothetical: checking this plugin under the folder name
 * `rapls-sitemap-dist` produced 320 TextDomainMismatch errors and nothing else.
 *
 * So the header may be renamed for display — but only to something that still
 * sanitises to the text domain. Everything else here is a field the directory
 * either requires or truncates.
 */
$readme = (string) file_get_contents( $root . '/readme.txt' );
$plugin = substr( (string) file_get_contents( $root . '/rapls-sitemap.php' ), 0, 1600 );

/**
 * One `Key: value` line out of a plugin header or a readme header block.
 */
function meta( $text, $key, $starred ) {
	$pattern = $starred
		? '/^\s\*\s' . preg_quote( $key, '/' ) . ':\s*(.+)$/m'
		: '/^' . preg_quote( $key, '/' ) . ':\s*(.+)$/m';

	return preg_match( $pattern, $text, $m ) ? trim( $m[1] ) : null;
}

$name  = (string) meta( $plugin, 'Plugin Name', true );
$slug  = trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $name ) ), '-' );
$title = trim( (string) strtok( $readme, "\n" ), "= \t\r" );

check( 'rapls-sitemap' === $slug, 'the plugin name still sanitises to the slug the directory would assign', $slug );
check( $slug === meta( $plugin, 'Text Domain', true ), 'and the text domain is that same slug' );
check( $title === $name, 'the readme title and the header name are the same string', "{$title} / {$name}" );

// A version in three places, and a release is wrong if any one of them lags.
check(
	meta( $plugin, 'Version', true ) === meta( $readme, 'Stable tag', false ),
	'the header version and the readme stable tag agree'
);
check(
	false !== strpos( $plugin, "RAPLS_SITEMAP_VERSION', '" . meta( $plugin, 'Version', true ) . "'" ),
	'and the version constant agrees with both'
);

foreach ( array( 'Requires at least', 'Requires PHP', 'License', 'License URI' ) as $field ) {
	check(
		meta( $plugin, $field, true ) === meta( $readme, $field, false ),
		sprintf( '"%s" is the same in the header and the readme', $field )
	);
}

// Fields the directory requires, and the two it silently truncates.
foreach ( array( 'Contributors', 'Donate link', 'Tags', 'Tested up to' ) as $field ) {
	check( null !== meta( $readme, $field, false ), sprintf( 'readme.txt declares "%s"', $field ) );
}

check( null !== meta( $plugin, 'Plugin URI', true ), 'the header points at the plugin\'s own page' );
check( null === meta( $plugin, 'Update URI', true ), 'and does not claim its own update server' );

$tags = array_filter( array_map( 'trim', explode( ',', (string) meta( $readme, 'Tags', false ) ) ) );
check( count( $tags ) <= 5, 'at most five tags, because the directory reads no more', (string) count( $tags ) );

// The short description is the first non-blank line under the header block.
$lines = preg_split( '/\R/', $readme );
$short = '';
$blank = false;
foreach ( $lines as $i => $line ) {
	if ( $i > 5 && '' === trim( $line ) ) {
		$blank = true;
		continue;
	}
	if ( $blank && '' !== trim( $line ) ) {
		$short = trim( $line );
		break;
	}
}
check( '' !== $short && strlen( $short ) <= 150, 'the short description fits the 150-character limit', (string) strlen( $short ) );

// The directory's own handbook: "a readme.txt file larger than 10k may result
// in errors". Nothing warns you locally — the file just grows, and the parsing
// goes strange on a page you cannot test until the plugin is live. Detail
// belongs on the plugin's own site, which is what the readme links to.
$readme_size = strlen( $readme );
check( $readme_size < 10000, 'readme.txt stays under the directory\'s 10k limit', $readme_size . ' bytes' );

// The LICENSE was copied from a sibling plugin and still said "Rapls Relay" on
// its first line, which nothing in the build or the test suite looked at. GPL
// asks for the name of the program it covers, and this one is shipped.
$license = trim( (string) strtok( (string) file_get_contents( $root . '/LICENSE' ), "\n" ) );
check( $license === $name, 'the LICENSE names this plugin and not a sibling', $license );

/* --- README.md links point at things the public repository has ----------- */

/*
 * `README.md` is the GitHub front page, and this repository is public while
 * `/docs/`, `/.claude/` and `CLAUDE.md` are not — `.gitignore` keeps them
 * local. A link from the front page to any of them is a 404 for every reader
 * and looks fine from here, where the file is sitting on disk. Existence is
 * therefore the wrong question; `git check-ignore` is the right one.
 *
 * Skipped rather than failed where git is unavailable, so the suite still runs
 * from an unpacked tarball.
 */
exec( 'command -v git >/dev/null 2>&1 && git -C ' . escapeshellarg( $root ) . ' rev-parse --git-dir 2>/dev/null', $ignored, $status );

if ( 0 !== $status ) {
	check( true, 'README links are checked against .gitignore (skipped: no git here)' );
} else {
	preg_match_all( '/\]\(\s*(\.\/[^)\s#]+|(?!\.\.\/\.\.\/)[A-Za-z0-9_][^):\s#]*)\s*\)/', (string) file_get_contents( $root . '/README.md' ), $links );

	$broken = array();
	foreach ( array_unique( $links[1] ) as $target ) {
		$relative = ltrim( $target, './' );

		// A path git ignores is not in the repository, whatever is on disk.
		exec(
			'git -C ' . escapeshellarg( $root ) . ' check-ignore -q ' . escapeshellarg( $relative ) . ' 2>/dev/null',
			$out,
			$ignores
		);

		if ( 0 === $ignores || ! file_exists( $root . '/' . $relative ) ) {
			$broken[] = $target;
		}
	}

	check(
		array() === $broken,
		'every relative link in README.md reaches a file the public repository has',
		implode( ', ', $broken )
	);
}

scrub( $tmp );

summary();
