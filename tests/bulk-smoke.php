<?php
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
    private string $error;
    public function __construct( string $code, string $message ) { $this->error = $message; }
    public function get_error_message(): string { return $this->error; }
}
require_once dirname( __DIR__ ) . '/includes/class-wvm-bulk.php';

function bulk_assert( bool $ok, string $message ): void {
    if ( ! $ok ) {
        fwrite( STDERR, "FAIL bulk parser: {$message}\n" );
        exit( 1 );
    }
}

function csv_test( string $content ) {
    $path = tempnam( sys_get_temp_dir(), 'wvm-csv-' );
    file_put_contents( $path, $content );
    try {
        return WVM_Bulk::parse_file( $path );
    } finally {
        unlink( $path );
    }
}

$rows = csv_test( "\xEF\xBB\xBFsku;medida;precio\r\nCOL-001;90x190;299,90\r\nCOL-001;135x190;384.50\r\n" );
bulk_assert( is_array( $rows ) && count( $rows ) === 2, 'UTF-8 BOM and CRLF accepted' );
bulk_assert( $rows[0]['price'] === '299.90', 'comma decimal normalized' );

$escaped = csv_test( "sku;medida;precio\n'=INJECTED;'-90x190;199\n" );
bulk_assert( is_array( $escaped ) && $escaped[0]['sku'] === '=INJECTED' && $escaped[0]['size'] === '-90x190', 'spreadsheet formula-safe export can round-trip' );
bulk_assert( WVM_Bulk::export_value( '=INJECTED' ) === "'=INJECTED", 'export protects formulas' );
bulk_assert( WVM_Bulk::export_value( '@FORMULA' ) === "'@FORMULA", 'export protects at-sign formulas' );
bulk_assert( WVM_Bulk::export_value( 'SKU-001' ) === 'SKU-001', 'normal SKU unchanged' );

$bad = array(
    "Sku;measure;amount\nCOL;90;100\n" => 'bad heading',
    "sku;medida;precio\nCOL;90;0\n" => 'zero amount',
    "sku;medida;precio\nCOL;90;-10\n" => 'negative amount',
    "sku;medida;precio\nCOL;90;1e10\n" => 'scientific amount',
    "sku;medida;precio\nCOL;90;100;EXTRA\n" => 'extra column',
    "sku;medida;precio\nCOL;;100\n" => 'missing size',
    "sku;medida;precio\n" => 'empty csv',
);
foreach ( $bad as $csv => $description ) {
    bulk_assert( csv_test( $csv ) instanceof WP_Error, $description . ' rejected' );
}
$limit = "sku;medida;precio\n" . str_repeat( "SKU;90x190;100\n", 1001 );
bulk_assert( csv_test( $limit ) instanceof WP_Error, '1001 rows rejected' );

echo "WVM bulk parser PASS: BOM, semicolon, comma decimals, formula-safe roundtrip, malformed rows and limits.\n";
