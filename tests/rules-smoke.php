<?php
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/class-wvm-pricing.php';
require_once dirname( __DIR__ ) . '/includes/class-wvm-rules.php';

function check_rule( bool $condition, string $message ): void {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

$options = array(
    'size' => array( '90x190', '135x190' ),
    'color' => array( 'Beige', 'Gris' ),
    'thickness' => array( '25 cm', '30 cm' ),
    'opening' => array( 'Frontal', 'Lateral' ),
);
$matrix = array( '90x190' => '300.00', '135x190' => '350.00' );

$extras = WVM_Rules::validate_surcharges(
    $options,
    array( 'color', 'color', 'opening', 'opening' ),
    array( 'Beige', 'Gris', 'Frontal', 'Lateral' ),
    array( '0', '25,50', '0.00', '65' )
);
check_rule( is_array( $extras ), 'valid extras accepted' );
check_rule( $extras['color']['Gris'] === '25.50', 'comma surcharge decimal normalized' );
check_rule( WVM_Rules::validate_surcharges(
    $options, array( 'color' ), array( 'Gris' ), array( '-10' )
) === null, 'negative surcharge rejected' );
check_rule( WVM_Rules::validate_surcharges(
    $options, array( 'color' ), array( 'Rojo' ), array( '10' )
) === null, 'unknown option rejected' );
check_rule( WVM_Rules::validate_surcharges(
    $options, array( 'color', 'color' ), array( 'Gris', 'Gris' ), array( '10', '20' )
) === null, 'duplicate supplement rejected' );

$rules = WVM_Rules::validate_exclusions(
    $options, array( 'size' ), array( '90x190' ),
    array( 'opening' ), array( 'Lateral' )
);
check_rule( is_array( $rules ) && count( $rules ) === 1, 'valid exclusion accepted' );
check_rule( WVM_Rules::validate_exclusions(
    $options, array( 'size' ), array( '90x190' ),
    array( 'size' ), array( '135x190' )
) === null, 'same-attribute exclusion rejected' );
check_rule( WVM_Rules::validate_exclusions(
    $options, array( 'size', 'opening' ), array( '90x190', 'Lateral' ),
    array( 'opening', 'size' ), array( 'Lateral', '90x190' )
) === null, 'reversed duplicate exclusion rejected' );

$invalid = array( 'size' => '90x190', 'color' => 'Gris', 'opening' => 'Lateral' );
$valid = array( 'size' => '135x190', 'color' => 'Gris', 'opening' => 'Lateral' );
check_rule( ! WVM_Rules::allowed( $invalid, $rules ), 'unavailable pair blocked' );
check_rule( WVM_Rules::allowed( $valid, $rules ), 'other combination remains available' );
check_rule( WVM_Rules::total( $matrix, $extras, $valid ) === '440.5000', 'size and optional surcharges combined' );
check_rule( WVM_Rules::total( $matrix, $extras, array( 'size' => '200x200' ) ) === null, 'no price for unknown size' );
check_rule( WVM_Rules::validate_stored( $options, $extras, $rules ), 'saved rules accepted' );
$removed = $options;
$removed['opening'] = array( 'Frontal' );
check_rule( ! WVM_Rules::validate_stored( $removed, $extras, $rules ), 'stale template change blocks sale' );
check_rule( ! WVM_Rules::validate_stored( $options, array( 'color' => array( 'Rojo' => '1' ) ), array() ), 'unknown stored surcharge blocks sale' );
echo "WVM pricing and compatibility rule smoke tests passed\n";
