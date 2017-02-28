<?php

declare(strict_types=1);

/*
 * This file is part of the RollerworksSearch package.
 *
 * (c) Sebastiaan Stok <s.stok@rollerscapes.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Rollerworks\Component\Search\Tests\Doctrine\Orm;

use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Rollerworks\Component\Search\Doctrine\Dbal\ColumnConversion;
use Rollerworks\Component\Search\Doctrine\Dbal\ConversionHints;
use Rollerworks\Component\Search\Doctrine\Dbal\ValueConversion;
use Rollerworks\Component\Search\Extension\Core\Type\DateType;
use Rollerworks\Component\Search\Extension\Core\Type\IntegerType;
use Rollerworks\Component\Search\SearchCondition;
use Rollerworks\Component\Search\SearchConditionBuilder;
use Rollerworks\Component\Search\Value\ValuesGroup;

/**
 * NativeWhereBuilderTest.
 *
 * This doesn't do extensive query tests as this handled by the QueryPlatform,
 * and is already tested in DBAL package.
 *
 * Note: In DQL it's not possible to reference a JOINED property (I.customer),
 * while in the NativeQuery it is possible and the preferred method.
 */
class NativeWhereBuilderTest extends OrmTestCase
{
    private function getWhereBuilder(SearchCondition $condition)
    {
        $rsm = new ResultSetMappingBuilder($this->em);
        $rsm->addRootEntityFromClassMetadata(
            'Rollerworks\Component\Search\Tests\Doctrine\Orm\Fixtures\Entity\ECommerceInvoice',
            'I'
        );

        $rsm->addJoinedEntityFromClassMetadata(
            'Rollerworks\Component\Search\Tests\Doctrine\Orm\Fixtures\Entity\ECommerceCustomer',
            'C',
            'I',
            'customer',
            ['id' => 'customer_id']
        );

        $querySmt = $this->em->createNativeQuery(
            'SELECT I FROM Invoice I JOIN customer AS C ON I.customer = C.id',
            $rsm
        );

        $whereBuilder = $this->getOrmFactory()->createWhereBuilder($querySmt, $condition);
        $whereBuilder->setDefaultEntity(self::INVOICE_CLASS, 'I');
        $whereBuilder->setField('id', 'id', null, null, 'smallint');
        $whereBuilder->setField('customer', 'customer', null, null, 'integer');
        //$whereBuilder->setField('credit_parent#0', 'parent');

        $whereBuilder->setDefaultEntity(self::CUSTOMER_CLASS, 'C');
        $whereBuilder->setField('customer_name#first_name', 'firstName');
        $whereBuilder->setField('customer_name#last_name', 'lastName');
        $whereBuilder->setField('customer_birthday', 'birthday');

        return $whereBuilder;
    }

    public function testSimpleQuery()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->addSimpleValue(2)
                ->addSimpleValue(5)
            ->end()
        ->getSearchCondition();

        $whereBuilder = $this->getWhereBuilder($condition);

        if ('sqlite' === $this->conn->getDatabasePlatform()->getName()) {
            $this->assertEquals('((I.customer IN(2, 5)))', $whereBuilder->getWhereClause());
        } else {
            $this->assertEquals("((I.customer IN('2', '5')))", $whereBuilder->getWhereClause());
        }
    }

    public function testEmptyResult()
    {
        $condition = new SearchCondition($this->getFieldSet(), new ValuesGroup());
        $whereBuilder = $this->getWhereBuilder($condition);

        $this->assertEquals('', $whereBuilder->getWhereClause());
    }

    public function testColumnConversion()
    {
        $converter = $this->createMock(ColumnConversion::class);
        $converter
            ->expects($this->atLeastOnce())
            ->method('convertColumn')
            ->willReturnCallback(function ($column, array $options, ConversionHints $hints) {
                self::assertArraySubset(['grouping' => true], $options);
                self::assertEquals('I', $hints->field->alias);
                self::assertEquals('I.customer', $hints->column);

                return "CAST($column AS customer_type)";
            })
        ;

        $fieldSetBuilder = $this->getFieldSet(false);
        $fieldSetBuilder->add('customer', IntegerType::class, ['grouping' => true, 'doctrine_dbal_conversion' => $converter]);

        $condition = SearchConditionBuilder::create($fieldSetBuilder->getFieldSet())
            ->field('customer')
                ->addSimpleValue(2)
                ->addSimpleValue(5)
            ->end()
        ->getSearchCondition();

        $whereBuilder = $this->getWhereBuilder($condition);

        if ('sqlite' === $this->conn->getDatabasePlatform()->getName()) {
            $this->assertEquals('((CAST(I.customer AS customer_type) IN(2, 5)))', $whereBuilder->getWhereClause());
        } else {
            $this->assertEquals("((CAST(I.customer AS customer_type) IN('2', '5')))", $whereBuilder->getWhereClause());
        }
    }

    public function testValueConversion()
    {
        $converter = $this->createMock(ValueConversion::class);
        $converter
            ->expects($this->atLeastOnce())
            ->method('convertValue')
            ->willReturnCallback(function ($value, array $options) {
                self::assertArraySubset(['grouping' => true], $options);

                return "get_customer_type($value)";
            })
        ;

        $fieldSetBuilder = $this->getFieldSet(false);
        $fieldSetBuilder->add('customer', IntegerType::class, ['grouping' => true, 'doctrine_dbal_conversion' => $converter]);

        $condition = SearchConditionBuilder::create($fieldSetBuilder->getFieldSet())
            ->field('customer')
                ->addSimpleValue(2)
                ->addSimpleValue(5)
            ->end()
        ->getSearchCondition();

        $whereBuilder = $this->getWhereBuilder($condition);
        self::assertEquals('(((I.customer = get_customer_type(2) OR I.customer = get_customer_type(5))))', $whereBuilder->getWhereClause());
    }

    public function testConversionStrategyValue()
    {
        $converter = $this->createMock(ValueConversionStrategy::class);
        $converter
            ->expects($this->atLeastOnce())
            ->method('getConversionStrategy')
            ->willReturnCallback(function ($value) {
                if (!$value instanceof \DateTime && !is_int($value)) {
                    throw new \InvalidArgumentException('Only integer/string and DateTime are accepted.');
                }

                if ($value instanceof \DateTime) {
                    return 2;
                }

                return 1;
            })
        ;

        $converter
            ->expects($this->atLeastOnce())
            ->method('convertValue')
            ->willReturnCallback(function ($value, array $passedOptions, ConversionHints $hints) {
                self::assertArraySubset(['pattern' => 'dd-MM-yy'], $passedOptions);

                if ($value instanceof \DateTime) {
                    self::assertEquals(2, $hints->conversionStrategy);

                    return 'CAST('.$hints->connection->quote($value->format('Y-m-d')).' AS AGE)';
                }

                self::assertEquals(1, $hints->conversionStrategy);

                return $value;
            })
        ;

        $fieldSet = $this->getFieldSet(false);
        $fieldSet->add('customer_birthday', DateType::class, ['doctrine_dbal_conversion' => $converter, 'pattern' => 'dd-MM-yy']);

        $condition = SearchConditionBuilder::create($fieldSet->getFieldSet())
            ->field('customer_birthday')
                ->addSimpleValue(18)
                ->addSimpleValue(new \DateTime('2001-01-15', new \DateTimeZone('UTC')))
            ->end()
        ->getSearchCondition();

        $whereBuilder = $this->getWhereBuilder($condition);
        self::assertEquals(
            "(((C.birthday = 18 OR C.birthday = CAST('2001-01-15' AS AGE))))",
            $whereBuilder->getWhereClause()
        );
    }

    public function testUpdateQuery()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->addSimpleValue(2)
            ->end()
        ->getSearchCondition();

        $whereBuilder = $this->getWhereBuilder($condition);

        $whereCase = $whereBuilder->getWhereClause();
        $whereBuilder->updateQuery();

        if ('sqlite' === $this->conn->getDatabasePlatform()->getName()) {
            $this->assertEquals('((I.customer IN(2)))', $whereCase);
            $this->assertEquals(
            'SELECT I FROM Invoice I JOIN customer AS C ON I.customer = C.id WHERE ((I.customer IN(2)))',
            $whereBuilder->getQuery()->getSQL()
        );
        } else {
            $this->assertEquals("((I.customer IN('2')))", $whereCase);
            $this->assertEquals(
                "SELECT I FROM Invoice I JOIN customer AS C ON I.customer = C.id WHERE ((I.customer IN('2')))",
                $whereBuilder->getQuery()->getSQL()
            );
        }
    }

    public function testUpdateQueryWithNoResult()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())->getSearchCondition();
        $whereBuilder = $this->getWhereBuilder($condition);

        $whereCase = $whereBuilder->getWhereClause();
        $whereBuilder->updateQuery();

        $this->assertEquals('', $whereCase);
        $this->assertEquals(
            'SELECT I FROM Invoice I JOIN customer AS C ON I.customer = C.id',
            $whereBuilder->getQuery()->getSQL()
        );
    }
}
