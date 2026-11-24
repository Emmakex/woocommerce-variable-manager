<?php
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/class-wvm-pricing.php';

function expect( bool $condition, string $message ): void {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

$sizes = array( '90x190', '135x190' );
$valid = WVM_Pricing::validate_matrix( $sizes, $sizes, array( '299,99', '384.50' ) );
expect( $valid === array( '90x190' => '299.99', '135x190' => '384.50' ), 'comma decimal and mapping' );
expect( WVM_Pricing::price( $valid, '135x190' ) === '384.50', 'exact price' );
expect( (float) WVM_Pricing::minimum( $valid ) === 299.99, 'minimum price' );
expect( WVM_Pricing::validate_matrix( $sizes, array( '90x190', '90x190' ), array( '299', '300' ) ) === null, 'reject duplicate size' );
expect( WVM_Pricing::validate_matrix( $sizes, array( '90x190' ), array( '299' ) ) === null, 'reject incomplete matrix' );
expect( WVM_Pricing::validate_matrix( $sizes, $sizes, array( '299', '0' ) ) === null, 'reject zero price' );
expect( WVM_Pricing::validate_matrix( $sizes, $sizes, array( '299', '-1' ) ) === null, 'reject negative price' );
expect( WVM_Pricing::validate_matrix( $sizes, $sizes, array( '299', '1.2.3' ) ) === null, 'reject malformed decimal' );
expect( WVM_Pricing::price( $valid, '200x200' ) === null, 'reject unavailable size' );
echo "WVM pricing smoke tests passed\n";
