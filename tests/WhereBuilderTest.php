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

namespace Rollerworks\Component\Search\Tests\Doctrine\Dbal;

use Doctrine\DBAL\Connection;
use Rollerworks\Component\Search\Doctrine\Dbal\ColumnConversion;
use Rollerworks\Component\Search\Doctrine\Dbal\ConversionHints;
use Rollerworks\Component\Search\Doctrine\Dbal\ValueConversion;
use Rollerworks\Component\Search\Extension\Core\Type\IntegerType;
use Rollerworks\Component\Search\Extension\Core\Type\TextType;
use Rollerworks\Component\Search\SearchCondition;
use Rollerworks\Component\Search\SearchConditionBuilder;
use Rollerworks\Component\Search\Value\Compare;
use Rollerworks\Component\Search\Value\ExcludedRange;
use Rollerworks\Component\Search\Value\PatternMatch;
use Rollerworks\Component\Search\Value\Range;
use Rollerworks\Component\Search\Value\ValuesGroup;

final class WhereBuilderTest extends DbalTestCase
{
    private function getWhereBuilder(SearchCondition $condition, Connection $connection = null)
    {
        $whereBuilder = $this->getDbalFactory()->createWhereBuilder(
            $connection ?: $this->getConnectionMock(),
            $condition
        );

        $whereBuilder->setField('customer', 'customer', 'I', 'integer');
        $whereBuilder->setField('customer_name', 'name', 'C', 'string');
        $whereBuilder->setField('customer_birthday', 'birthday', 'C', 'date');
        $whereBuilder->setField('status', 'status', 'I', 'integer');
        $whereBuilder->setField('label', 'label', 'I', 'string');

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

        $this->assertEquals('((I.customer IN(2, 5)))', $whereBuilder->getWhereClause());
    }

    public function testQueryWithPrepend()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->addSimpleValue(2)
                ->addSimpleValue(5)
            ->end()
        ->getSearchCondition();

        $whereBuilder = $this->getWhereBuilder($condition);

        $this->assertEquals('WHERE ((I.customer IN(2, 5)))', $whereBuilder->getWhereClause('WHERE '));
    }

    public function testEmptyQueryWithPrepend()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('id')
                ->addSimpleValue(2)
                ->addSimpleValue(5)
            ->end()
        ->getSearchCondition();

        $whereBuilder = $this->getWhereBuilder($condition);

        $this->assertEquals('', $whereBuilder->getWhereClause('WHERE '));
    }

    public function testQueryWithMultipleFields()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->addSimpleValue(2)
                ->addSimpleValue(5)
            ->end()
            ->field('status')
                ->addSimpleValue(2)
                ->addSimpleValue(5)
            ->end()
        ->getSearchCondition();

        $whereBuilder = $this->getWhereBuilder($condition);

        $this->assertEquals('((I.customer IN(2, 5)) AND (I.status IN(2, 5)))', $whereBuilder->getWhereClause());
    }

    public function testQueryWithCombinedField()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->addSimpleValue(2)
                ->addSimpleValue(5)
            ->end()
        ->getSearchCondition();

        $whereBuilder = $this->getWhereBuilder($condition);
        $whereBuilder->setField('customer#1', 'id');
        $whereBuilder->setField('customer#2', 'number2');

        $this->assertEquals('(((id IN(2, 5) OR number2 IN(2, 5))))', $whereBuilder->getWhereClause());
    }

    public function testQueryWithCombinedFieldAndCustomAlias()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->addSimpleValue(2)
                ->addSimpleValue(5)
            ->end()
        ->getSearchCondition();

        $whereBuilder = $this->getWhereBuilder($condition);
        $whereBuilder->setField('customer#1', 'id');
        $whereBuilder->setField('customer#2', 'number2', 'C', 'string');

        $this->assertEquals('(((id IN(2, 5) OR C.number2 IN(2, 5))))', $whereBuilder->getWhereClause());
    }

    public function testEmptyResult()
    {
        $connection = $this->getConnectionMock();
        $condition = new SearchCondition($this->getFieldSet(), new ValuesGroup());
        $whereBuilder = $this->getWhereBuilder($condition, $connection);

        $this->assertEquals('', $whereBuilder->getWhereClause());
    }

    public function testExcludes()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->addExcludedSimpleValue(2)
                ->addExcludedSimpleValue(5)
            ->end()
        ->getSearchCondition();

        $whereBuilder = $this->getWhereBuilder($condition);

        $this->assertEquals('((I.customer NOT IN(2, 5)))', $whereBuilder->getWhereClause());
    }

    public function testIncludesAndExcludes()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->addSimpleValue(2)
                ->addExcludedSimpleValue(5)
            ->end()
        ->getSearchCondition();

        $whereBuilder = $this->getWhereBuilder($condition);

        $this->assertEquals('((I.customer IN(2) AND I.customer NOT IN(5)))', $whereBuilder->getWhereClause());
    }

    public function testRanges()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->add(new Range(2, 5))
                ->add(new Range(10, 20))
                ->add(new Range(60, 70, false))
                ->add(new Range(100, 150, true, false))
            ->end()
        ->getSearchCondition();

        $whereBuilder = $this->getWhereBuilder($condition);

        $this->assertEquals(
            '((((I.customer >= 2 AND I.customer <= 5) OR (I.customer >= 10 AND I.customer <= 20) OR '.
            '(I.customer > 60 AND I.customer <= 70) OR (I.customer >= 100 AND I.customer < 150))))',
            $whereBuilder->getWhereClause()
        );
    }

    public function testExcludedRanges()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->add(new ExcludedRange(2, 5))
                ->add(new ExcludedRange(10, 20))
                ->add(new ExcludedRange(60, 70, false))
                ->add(new ExcludedRange(100, 150, true, false))
            ->end()
        ->getSearchCondition();

        $whereBuilder = $this->getWhereBuilder($condition);

        $this->assertEquals(
            '((((I.customer <= 2 OR I.customer >= 5) AND (I.customer <= 10 OR I.customer >= 20) AND '.
            '(I.customer < 60 OR I.customer >= 70) AND (I.customer <= 100 OR I.customer > 150))))',
            $whereBuilder->getWhereClause()
        );
    }

    public function testSingleComparison()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->add(new Compare(2, '>'))
            ->end()
        ->getSearchCondition();

        $whereBuilder = $this->getWhereBuilder($condition);

        $this->assertEquals('((I.customer > 2))', $whereBuilder->getWhereClause());
    }

    public function testMultipleComparisons()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->add(new Compare(2, '>'))
                ->add(new Compare(10, '<'))
            ->end()
        ->getSearchCondition();

        $whereBuilder = $this->getWhereBuilder($condition);

        $this->assertEquals(
            '(((I.customer > 2 AND I.customer < 10)))',
            $whereBuilder->getWhereClause()
        );
    }

    public function testMultipleComparisonsWithGroups()
    {
        // Use two subgroups here as the comparisons are AND to each other
        // but applying them in the head group would ignore subgroups
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->group()
                ->field('customer')
                    ->add(new Compare(2, '>'))
                    ->add(new Compare(10, '<'))
                    ->addSimpleValue(20)
                ->end()
            ->end()
            ->group()
                ->field('customer')
                    ->add(new Compare(30, '>'))
                ->end()
            ->end()
        ->getSearchCondition();

        $whereBuilder = $this->getWhereBuilder($condition);

        $this->assertEquals(
            '((((I.customer IN(20) OR (I.customer > 2 AND I.customer < 10)))) OR ((I.customer > 30)))',
            $whereBuilder->getWhereClause()
        );
    }

    public function testExcludingComparisons()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->add(new Compare(2, '<>'))
                ->add(new Compare(5, '<>'))
            ->end()
        ->getSearchCondition();

        $whereBuilder = $this->getWhereBuilder($condition);

        $this->assertEquals(
            '((I.customer <> 2 AND I.customer <> 5))',
            $whereBuilder->getWhereClause()
        );
    }

    public function testExcludingComparisonsWithNormal()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->add(new Compare(35, '<>'))
                ->add(new Compare(45, '<>'))
                ->add(new Compare(30, '>'))
                ->add(new Compare(50, '<'))
            ->end()
        ->getSearchCondition();

        $whereBuilder = $this->getWhereBuilder($condition);

        $this->assertEquals(
            '(((I.customer > 30 AND I.customer < 50) AND I.customer <> 35 AND I.customer <> 45))',
            $whereBuilder->getWhereClause()
        );
    }

    public function testPatternMatchers()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer_name')
                ->add(new PatternMatch('foo', PatternMatch::PATTERN_STARTS_WITH))
                ->add(new PatternMatch('fo\\\'o', PatternMatch::PATTERN_STARTS_WITH))
                ->add(new PatternMatch('bar', PatternMatch::PATTERN_NOT_ENDS_WITH, true))
                ->add(new PatternMatch('(foo|bar)', PatternMatch::PATTERN_REGEX))
                ->add(new PatternMatch('(doctor|who)', PatternMatch::PATTERN_REGEX, true))
                ->add(new PatternMatch('My name', PatternMatch::PATTERN_EQUALS))
                ->add(new PatternMatch('Last', PatternMatch::PATTERN_NOT_EQUALS))
                ->add(new PatternMatch('Spider', PatternMatch::PATTERN_EQUALS, true))
                ->add(new PatternMatch('Piggy', PatternMatch::PATTERN_NOT_EQUALS, true))
            ->end()
        ->getSearchCondition();

        $whereBuilder = $this->getWhereBuilder($condition);

        $this->assertEquals(
            "(((C.name LIKE '%foo' ESCAPE '\\' OR C.name LIKE '%fo\\'o' ESCAPE '\\' OR ".
            "RW_REGEXP('(foo|bar)', C.name, 'u') OR RW_REGEXP('(doctor|who)', C.name, 'ui') OR C.name = 'My name' OR ".
            "LOWER(C.name) = LOWER('Spider')) AND (LOWER(C.name) NOT LIKE LOWER('bar%') ESCAPE '\\' AND C.name <> 'Last' ".
            "AND LOWER(C.name) <> LOWER('Piggy'))))",
            $whereBuilder->getWhereClause()
        );
    }

    public function testSubGroups()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->group()
                ->field('customer')->addSimpleValue(2)->end()
            ->end()
            ->group()
                ->field('customer')->addSimpleValue(3)->end()
            ->end()
        ->getSearchCondition();

        $whereBuilder = $this->getWhereBuilder($condition);

        $this->assertEquals(
            '(((I.customer IN(2))) OR ((I.customer IN(3))))',
            $whereBuilder->getWhereClause()
        );
    }

    public function testSubGroupWithRootCondition()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->addSimpleValue(2)
            ->end()
            ->group()
                ->field('customer_name')
                    ->add(new PatternMatch('foo', PatternMatch::PATTERN_STARTS_WITH))
                ->end()
            ->end()
        ->getSearchCondition();

        $whereBuilder = $this->getWhereBuilder($condition);

        $this->assertEquals(
            "(((I.customer IN(2))) AND (((C.name LIKE '%foo' ESCAPE '\\'))))",
            $whereBuilder->getWhereClause()
        );
    }

    public function testOrGroupRoot()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet(), ValuesGroup::GROUP_LOGICAL_OR)
            ->field('customer')
                ->addSimpleValue(2)
            ->end()
            ->field('customer_name')
                ->add(new PatternMatch('foo', PatternMatch::PATTERN_STARTS_WITH))
            ->end()
        ->getSearchCondition();

        $whereBuilder = $this->getWhereBuilder($condition);

        $this->assertEquals(
            "((I.customer IN(2)) OR (C.name LIKE '%foo' ESCAPE '\\'))",
            $whereBuilder->getWhereClause()
        );
    }

    public function testSubOrGroup()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->group()
                ->group(ValuesGroup::GROUP_LOGICAL_OR)
                    ->field('customer')
                        ->addSimpleValue(2)
                    ->end()
                    ->field('customer_name')
                        ->add(new PatternMatch('foo', PatternMatch::PATTERN_STARTS_WITH))
                    ->end()
                ->end()
            ->end()
        ->getSearchCondition();

        $whereBuilder = $this->getWhereBuilder($condition);

        $this->assertEquals(
            "((((I.customer IN(2)) OR (C.name LIKE '%foo' ESCAPE '\\'))))",
            $whereBuilder->getWhereClause()
        );
    }

    /**
     * @dataProvider provideFieldConversionTests
     *
     * @param string $expectWhereCase
     * @param array  $options
     */
    public function testFieldConversion($expectWhereCase, array $options = [])
    {
        $fieldSetBuilder = $this->getFieldSet(false);
        $fieldSetBuilder->add('customer', IntegerType::class, $options);

        $condition = SearchConditionBuilder::create($fieldSetBuilder->getFieldSet())
            ->field('customer')
                ->addSimpleValue(2)
            ->end()
        ->getSearchCondition();

        $whereBuilder = $this->getWhereBuilder($condition);
        $passedOptions = $options;

        $converter = $this->createMock(ColumnConversion::class);
        $converter
            ->expects($this->atLeastOnce())
            ->method('convertColumn')
            ->will($this->returnCallback(function ($column, array $options, ConversionHints $hints) use ($passedOptions) {
                self::assertArraySubset($passedOptions, $options);
                self::assertEquals('I', $hints->field->alias);
                self::assertEquals('I.customer', $hints->column);

                return "CAST($column AS customer_type)";
            }))
        ;

        $whereBuilder->setConverter('customer', $converter);

        $this->assertEquals($expectWhereCase, $whereBuilder->getWhereClause());
    }

    public function testValueConversion()
    {
        $fieldSet = $this->getFieldSet();
        $condition = SearchConditionBuilder::create($fieldSet)
            ->field('customer')
                ->addSimpleValue(2)
            ->end()
        ->getSearchCondition();

        $options = $fieldSet->get('customer')->getOptions();
        $whereBuilder = $this->getWhereBuilder($condition);

        $converter = $this->createMock(ValueConversion::class);
        $converter
            ->expects($this->atLeastOnce())
            ->method('convertValue')
            ->will($this->returnCallback(function ($value, array $passedOptions) use ($options) {
                self::assertEquals($options, $passedOptions);

                return "get_customer_type($value)";
            }))
        ;

        $converter
            ->expects($this->atLeastOnce())
            ->method('convertValue')
            ->will($this->returnArgument(0))
        ;

        $whereBuilder->setConverter('customer', $converter);

        $this->assertEquals('((I.customer = get_customer_type(2)))', $whereBuilder->getWhereClause());
    }

    public function testConversionStrategyValue()
    {
        $date = new \DateTime('2001-01-15', new \DateTimeZone('UTC'));

        $fieldSet = $this->getFieldSet();
        $condition = SearchConditionBuilder::create($fieldSet)
            ->field('customer_birthday')
                ->addSimpleValue(18)
                ->addSimpleValue($date)
            ->end()
        ->getSearchCondition();

        $options = $fieldSet->get('customer_birthday')->getOptions();
        $whereBuilder = $this->getWhereBuilder($condition);

        $converter = $this->createMock(ValueConversionStrategy::class);
        $converter
            ->expects($this->atLeastOnce())
            ->method('getConversionStrategy')
            ->will(
                $this->returnCallback(
                    function ($value) {
                        if (!$value instanceof \DateTime && !is_int($value)) {
                            throw new \InvalidArgumentException('Only integer/string and DateTime are accepted.');
                        }

                        if ($value instanceof \DateTime) {
                            return 2;
                        }

                        return 1;
                    }
                )
            )
        ;

        $converter
            ->expects($this->atLeastOnce())
            ->method('convertColumn')
            ->will(
                $this->returnCallback(
                    function ($column, array $passedOptions, ConversionHints $hints) use ($options) {
                        self::assertEquals($options, $passedOptions);

                        if (2 === $hints->conversionStrategy) {
                            return "search_conversion_age($column)";
                        }

                        self::assertEquals(1, $hints->conversionStrategy);

                        return $column;
                    }
                )
            )
        ;

        $converter
            ->expects($this->atLeastOnce())
            ->method('convertValue')
            ->will(
                $this->returnCallback(
                    function ($value, array $passedOptions, ConversionHints $hints) use ($options) {
                        self::assertEquals($options, $passedOptions);

                        if ($value instanceof \DateTime) {
                            self::assertEquals(2, $hints->conversionStrategy);
                        } else {
                            self::assertEquals(1, $hints->conversionStrategy);
                        }

                        if (2 === $hints->conversionStrategy) {
                            return 'CAST('.$hints->connection->quote($value->format('Y-m-d')).' AS DATE)';
                        }

                        self::assertEquals(1, $hints->conversionStrategy);

                        return $value;
                    }
                )
            )
        ;

        $whereBuilder->setConverter('customer_birthday', $converter);

        $this->assertEquals(
            "(((C.birthday = 18 OR search_conversion_age(C.birthday) = CAST('2001-01-15' AS DATE))))",
            $whereBuilder->getWhereClause()
        );
    }

    public function testConversionStrategyField()
    {
        $fieldSet = $this->getFieldSet(false);
        $fieldSet->add('customer_birthday', TextType::class);
        $fieldSet = $fieldSet->getFieldSet();

        $condition = SearchConditionBuilder::create($fieldSet)
            ->field('customer_birthday')
                ->addSimpleValue(18)
                ->addSimpleValue('2001-01-15')
            ->end()
        ->getSearchCondition();

        $options = $fieldSet->get('customer_birthday')->getOptions();

        $whereBuilder = $this->getWhereBuilder($condition);
        $whereBuilder->setField('customer_birthday', 'birthday', 'C', 'string');

        $test = $this;

        $converter = $this->createMock(ColumnConversionStrategy::class);
        $converter
            ->expects($this->atLeastOnce())
            ->method('getConversionStrategy')
            ->will(
                $this->returnCallback(
                    function ($value) {
                        if (!is_string($value) && !is_int($value)) {
                            throw new \InvalidArgumentException('Only integer/string is accepted.');
                        }

                        if (is_string($value)) {
                            return 2;
                        }

                        return 1;
                    }
                )
            )
        ;

        $converter
            ->expects($this->atLeastOnce())
            ->method('convertColumn')
            ->will(
                $this->returnCallback(
                    function ($column, array $passedOptions, ConversionHints $hints) use ($test, $options) {
                        $test->assertEquals($options, $passedOptions);

                        if (2 === $hints->conversionStrategy) {
                            return "search_conversion_age($column)";
                        }

                        $test->assertEquals(1, $hints->conversionStrategy);

                        return $column;
                    }
                )
            )
        ;

        $whereBuilder->setConverter('customer_birthday', $converter);

        $this->assertEquals(
            "(((C.birthday = 18 OR search_conversion_age(C.birthday) = '2001-01-15')))",
            $whereBuilder->getWhereClause()
        );
    }

    public static function provideFieldConversionTests()
    {
        return [
            [
                '((CAST(I.customer AS customer_type) IN(2)))',
            ],
            [
                '((CAST(I.customer AS customer_type) IN(2)))',
                ['grouping' => true],
            ],
        ];
    }
}
