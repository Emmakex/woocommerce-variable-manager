<?php
/**
 * Deterministic synthetic catalog test: no WordPress, database, orders,
 * network or staging hostname required.
 *
 * Run: php tests/synthetic-catalog.php
 */
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/class-wvm-pricing.php';
require_once dirname( __DIR__ ) . '/includes/class-wvm-rules.php';

function synthetic_assert( bool $condition, string $reason ): void {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL synthetic catalog: {$reason}\n" );
        exit( 1 );
    }
}

/** Cross-check prices using integer cents, independent of WVM_Rules::total(). */
function cents( string $value ): int {
    $parts = explode( '.', str_replace( ',', '.', $value ) );
    $decimals = substr( str_pad( $parts[1] ?? '', 2, '0', STR_PAD_RIGHT ), 0, 2 );
    return (int) $parts[0] * 100 + (int) $decimals;
}

function synthetic_combinations( array $options, array $keys, int $index = 0, array $chosen = array() ): array {
    if ( $index === count( $keys ) ) {
        return array( $chosen );
    }
    $combinations = array();
    $key = $keys[ $index ];
    foreach ( $options[ $key ] as $value ) {
        $next = $chosen;
        $next[ $key ] = $value;
        foreach ( synthetic_combinations( $options, $keys, $index + 1, $next ) as $choice ) {
            $combinations[] = $choice;
        }
    }
    return $combinations;
}

$path = __DIR__ . '/fixtures/catalog-synthetic.json';
$catalog = json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
synthetic_assert( $catalog['currency'] === 'EUR', 'EUR fixtures' );
synthetic_assert( count( $catalog['products'] ) === 8, 'eight synthetic products expected' );

$totals = array( 'combinations' => 0, 'allowed' => 0, 'blocked' => 0, 'products' => 0 );
$seen = array();

foreach ( $catalog['products'] as $fixture ) {
    $sku = $fixture['sku'];
    synthetic_assert( ! isset( $seen[ $sku ] ), "{$sku}: duplicate SKU" );
    $seen[ $sku ] = true;
    $options = $fixture['options'];

    $sizeKeys = $options['size'];
    $matrix = WVM_Pricing::validate_matrix( $sizeKeys, array_keys( $fixture['matrix'] ), array_values( $fixture['matrix'] ) );
    synthetic_assert( null !== $matrix, "{$sku}: invalid matrix" );

    $extraAttrs = $extraValues = $extraAmounts = array();
    foreach ( $fixture['surcharges'] as $key => $values ) {
        foreach ( $values as $value => $amount ) {
            $extraAttrs[] = $key;
            $extraValues[] = $value;
            $extraAmounts[] = $amount;
        }
    }
    $extras = WVM_Rules::validate_surcharges( $options, $extraAttrs, $extraValues, $extraAmounts );
    synthetic_assert( null !== $extras, "{$sku}: invalid surcharges" );

    $a_attr = $a_value = $b_attr = $b_value = array();
    foreach ( $fixture['exclusions'] as $rule ) {
        $a_attr[] = $rule['a_attr'];
        $a_value[] = $rule['a_value'];
        $b_attr[] = $rule['b_attr'];
        $b_value[] = $rule['b_value'];
    }
    $exclusions = WVM_Rules::validate_exclusions( $options, $a_attr, $a_value, $b_attr, $b_value );
    synthetic_assert( null !== $exclusions, "{$sku}: invalid exclusion rules" );
    synthetic_assert( WVM_Rules::validate_stored( $options, $extras, $exclusions ), "{$sku}: invalid stored state" );

    $sample = $fixture['sample'];
    synthetic_assert( WVM_Rules::allowed( $sample['selection'], $exclusions ), "{$sku}: sample unexpectedly unavailable" );
    $samplePrice = WVM_Rules::total( $matrix, $extras, $sample['selection'] );
    synthetic_assert( null !== $samplePrice, "{$sku}: sample lacks price" );
    synthetic_assert(
        number_format( (float) $samplePrice, 2, '.', '' ) === $sample['expected_total'],
        "{$sku}: wrong sample price {$samplePrice} instead of {$sample['expected_total']}"
    );

    if ( isset( $fixture['blocked'] ) ) {
        synthetic_assert( ! WVM_Rules::allowed( $fixture['blocked'], $exclusions ), "{$sku}: blocked configuration accepted" );
    }

    $keys = array_values( array_filter( array_keys( $options ), static function ( string $key ) use ( $options ): bool {
        return ! empty( $options[ $key ] );
    } ) );
    $combinations = synthetic_combinations( $options, $keys );
    foreach ( $combinations as $selection ) {
        $totals['combinations']++;
        $allowed = WVM_Rules::allowed( $selection, $exclusions );
        if ( ! $allowed ) {
            $totals['blocked']++;
            continue;
        }
        $totals['allowed']++;
        $expectedCents = cents( (string) $matrix[ $selection['size'] ] );
        foreach ( array( 'color', 'thickness', 'opening' ) as $attribute ) {
            $option = $selection[ $attribute ] ?? null;
            if ( null !== $option ) {
                $expectedCents += cents( (string) ( $extras[ $attribute ][ $option ] ?? '0' ) );
            }
        }
        $actual = WVM_Rules::total( $matrix, $extras, $selection );
        synthetic_assert( null !== $actual, "{$sku}: missing server-calculated price" );
        $actualCents = (int) round( (float) $actual * 100 );
        synthetic_assert(
            $actualCents === $expectedCents,
            "{$sku}: wrong price for " . json_encode( $selection ) . "; got {$actualCents}, expected {$expectedCents}"
        );
    }
    // Tampering and invalid catalog mutation MUST fail closed.
    synthetic_assert( null === WVM_Pricing::price( $matrix, '__unknown_measure__' ), "{$sku}: unknown measure accepted" );
    $staleOptions = $options;
    array_pop( $staleOptions['size'] );
    synthetic_assert(
        ! isset( $staleOptions['size'][0] ) || count( $staleOptions['size'] ) < count( $options['size'] ),
        "{$sku}: stale mutation ineffective"
    );
    $totals['products']++;
}

synthetic_assert( $totals['combinations'] === 112, 'unexpected fixture combination count' );
synthetic_assert( $totals['blocked'] === 19, 'excluded combination count changed' );
synthetic_assert( $totals['allowed'] === 93, 'allowed combination count changed' );

printf(
    "Synthetic catalog PASS: %d products, %d configurations, %d valid prices, %d blocked, 8 reference totals verified.\n",
    $totals['products'], $totals['combinations'], $totals['allowed'], $totals['blocked']
);
