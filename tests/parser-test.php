<?php
/**
 * Parser tests. Run with: php tests/parser-test.php
 *
 * @package CpanelTrafficStats
 */

define( 'CTS_TESTING', true );
require __DIR__ . '/../includes/class-cts-awstats-parser.php';
require __DIR__ . '/../includes/class-cts-webalizer-parser.php';

$failures = 0;
$count    = 0;

/**
 * Assert equality.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual   Actual.
 * @param string $msg      Label.
 */
function check( $expected, $actual, $msg ) {
	global $failures, $count;
	$count++;
	if ( $expected !== $actual ) {
		$failures++;
		echo "FAIL: $msg\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
	}
}

$fx = __DIR__ . '/fixtures/home/tmp';

// --- AWStats -------------------------------------------------------------
$ssl  = CTS_AWStats_Parser::parse( file_get_contents( $fx . '/awstats/ssl/awstats112026.example.com.txt' ) );
$http = CTS_AWStats_Parser::parse( file_get_contents( $fx . '/awstats/awstats112026.example.com.txt' ) );

check( 19, count( $ssl['days'] ), 'AWStats: 19 days parsed' );
check( 24, count( $ssl['hours'] ), 'AWStats: 24 hours' );
check( true, $ssl['summary']['visits'] > 0, 'AWStats: visits > 0' );
check( (int) round( $ssl['summary']['visits'] * 0.7 ) >= $ssl['summary']['unique'] - 1, true, 'AWStats: unique read from GENERAL' );
check( array_sum( array_column( $ssl['days'], 'visits' ) ), $ssl['summary']['visits'], 'AWStats: TotalVisits matches day sum' );
check( '20261119040012', $ssl['summary']['last_update'], 'AWStats: last update' );
check( true, isset( $ssl['browsers']['Chrome'] ), 'AWStats: Chrome versions grouped' );
check( true, isset( $ssl['os']['Windows'], $ssl['os']['iOS'], $ssl['os']['macOS'] ), 'AWStats: OS labels' );
check( true, isset( $ssl['search_engines']['Google'] ), 'AWStats: search engine label' );
check( true, isset( $ssl['keyphrases']['web design near me'] ), 'AWStats: + decoded in key phrases' );
check( true, isset( $ssl['keyphrases']['wordpress developer texas'] ), 'AWStats: %20 decoded in key phrases' );
check( 12, count( $ssl['pages'] ), 'AWStats: pages' );
check( 212, $ssl['errors'][404]['hits'], 'AWStats: 404 errors' );
check( 80, $ssl['not_found']['/wp-login.php.bak']['hits'], 'AWStats: 404 URLs' );
check( 840, $ssl['robots']['googlebot']['hits'], 'AWStats: robots' );
check( array( 2026, 11 ), CTS_AWStats_Parser::period_from_filename( 'awstats112026.example.com.txt' ), 'AWStats: period from file name' );
check( 'awstats012027.example.com.txt', CTS_AWStats_Parser::filename( 2027, 1, 'example.com' ), 'AWStats: file name' );

$merged = CTS_AWStats_Parser::merge( $http, $ssl );
check( $http['summary']['visits'] + $ssl['summary']['visits'], $merged['summary']['visits'], 'Merge: visits summed' );
check( $http['summary']['bandwidth'] + $ssl['summary']['bandwidth'], $merged['summary']['bandwidth'], 'Merge: bandwidth summed' );
check( $http['days'][20261105]['pages'] + $ssl['days'][20261105]['pages'], $merged['days'][20261105]['pages'], 'Merge: day pages summed' );
check( '20261119040012', $merged['summary']['last_update'], 'Merge: last_update kept as string' );
check( 424, $merged['errors'][404]['hits'], 'Merge: errors summed' );

// Empty / junk input should not blow up.
$empty = CTS_AWStats_Parser::parse( "garbage\nBEGIN_DAY 1\nnot numbers here\nEND_DAY\n" );
check( 0, $empty['summary']['hits'], 'AWStats: junk input gives zeros' );

// Windows line endings.
$crlf = CTS_AWStats_Parser::parse( str_replace( "\n", "\r\n", file_get_contents( $fx . '/awstats/ssl/awstats112026.example.com.txt' ) ) );
check( $ssl['summary'], $crlf['summary'], 'AWStats: CRLF files parse the same' );

// --- Webalizer -----------------------------------------------------------
$hist_ssl  = CTS_Webalizer_Parser::parse_history( file_get_contents( $fx . '/webalizerssl/webalizer.hist' ) );
$hist_http = CTS_Webalizer_Parser::parse_history( file_get_contents( $fx . '/webalizer/webalizer.hist' ) );
check( 12, count( $hist_ssl ), 'Webalizer: 12 months of history' );
check( '2026-11', array_key_first( $hist_ssl ), 'Webalizer: newest month first' );
check( 19, $hist_ssl['2026-11']['last_day'], 'Webalizer: last day' );

$hm = CTS_Webalizer_Parser::merge_history( $hist_http, $hist_ssl );
check( $hist_http['2026-11']['visits'] + $hist_ssl['2026-11']['visits'], $hm['2026-11']['visits'], 'Webalizer: history merge' );

$daily = CTS_Webalizer_Parser::parse_daily( file_get_contents( $fx . '/webalizerssl/usage_202611.html' ) );
check( 19, count( $daily ), 'Webalizer: 19 days in daily table' );
check( $daily[1]['visits'] * 14, $daily[1]['hits'], 'Webalizer: daily columns line up (hits)' );
check( $daily[1]['visits'] * 3, $daily[1]['pages'], 'Webalizer: daily columns line up (pages)' );

echo "\n$count checks, $failures failed\n";
exit( $failures ? 1 : 0 );
