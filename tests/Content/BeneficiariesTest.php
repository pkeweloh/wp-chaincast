<?php
/**
 * Parsing and validation of beneficiaries.
 *
 * @package Chaincast\Tests\Content
 */

declare(strict_types=1);

namespace Chaincast\Tests\Content;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Chaincast\Connector\Content\Beneficiaries;

final class BeneficiariesTest extends TestCase {

    public function testEmptyReturnsEmptyList(): void {
        $this->assertSame( [], Beneficiaries::parse( '' ) );
        $this->assertSame( [], Beneficiaries::parse( '   ' ) );
    }

    public function testParsesPercentagesToBasisPointsAndSortsByAccount(): void {
        $result = Beneficiaries::parse( 'un-curador:5, algun-proyecto:10' );

        $this->assertSame(
            [
                [ 'account' => 'algun-proyecto', 'weight' => 1000 ],
                [ 'account' => 'un-curador', 'weight' => 500 ],
            ],
            $result
        );
    }

    public function testAcceptsDecimalPercent(): void {
        $result = Beneficiaries::parse( 'cuenta-x:2.5' );
        $this->assertSame( [ [ 'account' => 'cuenta-x', 'weight' => 250 ] ], $result );
    }

    public function testNormalizesAccountToLowercase(): void {
        $result = Beneficiaries::parse( 'SkunkProj:1' );
        $this->assertSame( 'skunkproj', $result[0]['account'] );
    }

    public function testRejectsSumOver100(): void {
        $this->expectException( InvalidArgumentException::class );
        Beneficiaries::parse( 'aaa:60, bbb:50' );
    }

    public function testRejectsDuplicateAccount(): void {
        $this->expectException( InvalidArgumentException::class );
        Beneficiaries::parse( 'aaa:10, aaa:5' );
    }

    public function testRejectsInvalidAccount(): void {
        $this->expectException( InvalidArgumentException::class );
        Beneficiaries::parse( 'X:10' ); // too short.
    }

    public function testRejectsBadFormat(): void {
        $this->expectException( InvalidArgumentException::class );
        Beneficiaries::parse( 'sin-porcentaje' );
    }

    public function testRejectsOutOfRangePercent(): void {
        $this->expectException( InvalidArgumentException::class );
        Beneficiaries::parse( 'cuenta-y:0' );
    }

    public function testRejectsMoreThanEight(): void {
        $this->expectException( InvalidArgumentException::class );
        Beneficiaries::parse( 'ac1:1, ac2:1, ac3:1, ac4:1, ac5:1, ac6:1, ac7:1, ac8:1, ac9:1' );
    }

    public function testParseSafeSwallowsErrors(): void {
        $this->assertSame( [], Beneficiaries::parseSafe( 'aaa:60, bbb:50' ) );
        $this->assertSame(
            [ [ 'account' => 'aaa', 'weight' => 100 ] ],
            Beneficiaries::parseSafe( 'aaa:1' )
        );
    }
}
