<?php

/*
 * This file is part of the RollerworksSearch package.
 *
 * (c) Sebastiaan Stok <s.stok@rollerscapes.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Rollerworks\Component\Search;

/**
 * @author Sebastiaan Stok <s.stok@rollerscapes.net>
 */
interface SearchFactoryInterface
{
    /**
     * Create a new search field.
     *
     * @param string $name     Name of the field
     * @param string $type     Type of the field
     * @param array  $options  Array of options for building the field
     * @param bool   $required Is the field required in a ValuesGroup and must it
     *                         always have a value (default is false)
     *
     * @return FieldConfigInterface
     */
    public function createField($name, $type, array $options = array(), $required = false);

    /**
     * Create a new FieldsetBuilderInterface instance.
     *
     * @param string $name
     *
     * @return FieldsetBuilderInterface
     */
    public function createFieldSetBuilder($name);
}
