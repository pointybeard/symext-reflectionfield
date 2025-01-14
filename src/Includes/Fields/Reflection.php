<?php

declare(strict_types=1);

/*
 * This file is part of the "Reflection Field for Symphony CMS" repository.
 *
 * Copyright 2008-2017 Rowan Lewis, Symphonists
 * Copyright 2021 Alannah Kearney <hi@alannahkearney.com>
 *
 * For the full copyright and license information, please view the LICENCE
 * file that was distributed with this source code.
 */

if (!defined('__IN_SYMPHONY__')) {
    exit('<h2>Symphony Error</h2><p>You cannot directly access this file</p>');
}

class FieldReflection extends Field implements ExportableField
{
    protected static $compiling = 0;

    /*-------------------------------------------------------------------------
        Definition:
    -------------------------------------------------------------------------*/

    public function __construct()
    {
        parent::__construct();

        $this->_name = __('Reflection');

        // Set defaults:
        $this->set('show_column', 'yes');
        $this->set('allow_override', 'no');
        $this->set('fetch_associated_counts', 'no');
        $this->set('hide', 'no');
    }

    public function createTable()
    {
        $field_id = $this->get('id');

        return Symphony::Database()->query("
            CREATE TABLE IF NOT EXISTS `tbl_entries_data_{$field_id}` (
                `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `entry_id` INT(11) UNSIGNED NOT NULL,
                `handle` VARCHAR(255) DEFAULT NULL,
                `value` TEXT DEFAULT NULL,
                `value_formatted` TEXT DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entry_id` (`entry_id`),
                FULLTEXT KEY `value` (`value`),
                FULLTEXT KEY `value_formatted` (`value_formatted`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
        ");
    }

    public function allowDatasourceOutputGrouping()
    {
        return true;
    }

    public function allowDatasourceParamOutput()
    {
        return true;
    }

    public function canFilter()
    {
        return true;
    }

    public function canPrePopulate()
    {
        return true;
    }

    public function isSortable()
    {
        return true;
    }

    /*-------------------------------------------------------------------------
        Settings:
    -------------------------------------------------------------------------*/

    public function displaySettingsPanel(XMLElement &$wrapper, $errors = null)
    {
        parent::displaySettingsPanel($wrapper, $errors);

        $order = $this->get('sortorder');

        /*---------------------------------------------------------------------
            Text Formatter
        ---------------------------------------------------------------------*/

        $group = new XMLElement('div');
        $group->setAttribute('class', 'two columns');

        $group->appendChild($this->buildFormatterSelect(
            $this->get('formatter'),
            "fields[{$order}][formatter]",
            __('Text Formatter')
        ));

        /*---------------------------------------------------------------------
            XSLT
        ---------------------------------------------------------------------*/

        $label = Widget::Label('XSLT Utility');
        $label->setAttribute('class', 'column');

        $utilities = General::listStructure(UTILITIES, ['xsl'], false, 'asc', UTILITIES);
        $utilities = ($utilities['filelist']) ? $utilities['filelist'] : [];

        $xsltfile = $this->get('xsltfile');
        $options = [];
        $options[] = ['', empty($xsltfile), __('Disabled')];

        if (is_array($utilities)) {
            foreach ($utilities as $utility) {
                $options[] = [$utility, ($xsltfile == $utility), $utility];
            }
        }

        $label->appendChild(Widget::Select(
            "fields[{$order}][xsltfile]",
            $options
        ));

        $help = new XMLElement('p');
        $help->setAttribute('class', 'help');
        $help->setAttribute('style', 'margin-bottom:0');
        $help->setValue(__('XSLT will be applied to <code>entry</code> XML before <code>Expression</code> is evaluated.'));

        $label->appendChild($help);
        $group->appendChild($label);
        $wrapper->appendChild($group);

        /*---------------------------------------------------------------------
            Expression
        ---------------------------------------------------------------------*/

        $label = Widget::Label('Expression');
        $label->setAttribute('class', 'column');
        $label->appendChild(Widget::Input(
            "fields[{$order}][expression]",
            $this->get('expression')
        ));

        $help = new XMLElement('p');
        $help->setAttribute('class', 'help');

        $help->setValue(__('Use XPath to access other fields: <code>{//entry/field-one} static text {//entry/field-two}</code>.'));

        $label->appendChild($help);
        $wrapper->appendChild($label);

        /*---------------------------------------------------------------------
            Fetch Associated Entry Counts
        ---------------------------------------------------------------------*/

        $compact = new XMLElement('div');
        $compact->setAttribute('class', 'two columns');

        $label = Widget::Label();
        $label->setAttribute('class', 'column');
        $input = Widget::Input("fields[{$order}][fetch_associated_counts]", 'yes', 'checkbox');

        if ('yes' == $this->get('fetch_associated_counts')) {
            $input->setAttribute('checked', 'checked');
        }

        $label->setValue($input->generate().' '.__('Fetch associated entry counts for XPath'));
        $compact->appendChild($label);

        /*---------------------------------------------------------------------
            Allow Override
        ---------------------------------------------------------------------*/

        /*
        $label = Widget::Label();
        $input = Widget::Input("fields[{$order}][allow_override]", 'yes', 'checkbox');

        if ($this->get('allow_override') == 'yes') {
            $input->setAttribute('checked', 'checked');
        }

        $label->setValue($input->generate() . ' Allow value to be manually overridden');
        $wrapper->appendChild($label);
        */

        /*---------------------------------------------------------------------
            Hide input
        ---------------------------------------------------------------------*/

        $label = Widget::Label();
        $label->setAttribute('class', 'column');
        $input = Widget::Input("fields[{$order}][hide]", 'yes', 'checkbox');

        if ('yes' == $this->get('hide')) {
            $input->setAttribute('checked', 'checked');
        }

        $label->setValue($input->generate().' '.__('Hide this field on publish page'));
        $compact->appendChild($label);

        $this->appendShowColumnCheckbox($compact);
        $wrapper->appendChild($compact);
    }

    public function commit()
    {
        if (!parent::commit()) {
            return false;
        }

        $id = $this->get('id');
        $handle = $this->handle();

        if (false === $id) {
            return false;
        }

        $fields = [
            'field_id' => $id,
            'xsltfile' => $this->get('xsltfile'),
            'expression' => $this->get('expression'),
            'formatter' => $this->get('formatter'),
            'override' => $this->get('override'),
            'fetch_associated_counts' => $this->get('fetch_associated_counts'),
            'hide' => $this->get('hide'),
        ];

        return FieldManager::saveSettings($id, $fields);
    }

    /*-------------------------------------------------------------------------
        Publish:
    -------------------------------------------------------------------------*/

    public function displayPublishPanel(XMLElement &$wrapper, $data = null, $flagWithError = null, $fieldnamePrefix = null, $fieldnamePostfix = null, $entry_id = null)
    {
        $sortorder = $this->get('sortorder');
        $element_name = $this->get('element_name');
        $allow_override = null;

        if ('yes' != $this->get('override')) {
            $allow_override = [
                'disabled' => 'disabled',
            ];
        }

        if ('yes' != $this->get('hide')) {
            $value = isset($data['value_formatted'])
                ? $data['value_formatted']
                : null;
            $label = Widget::Label($this->get('label'));
            $label->appendChild(
                Widget::Input(
                    "fields{$fieldnamePrefix}[$element_name]{$fieldnamePostfix}",
                    $value, 'text', $allow_override
                )
            );
            $wrapper->appendChild($label);
        } else {
            $wrapper->addClass('irrelevant');
        }
    }

    /*-------------------------------------------------------------------------
        Input:
    -------------------------------------------------------------------------*/

    public function checkPostFieldData($data, &$message, $entry_id = null)
    {
        $driver = Symphony::ExtensionManager()->create('reflectionfield');
        $driver->registerField($this);

        return self::__OK__;
    }

    public function processRawFieldData($data, &$status, &$message = null, $simulate = false, $entry_id = null)
    {
        $status = self::__OK__;

        return [
            'handle' => null,
            'value' => null,
            'value_formatted' => null,
        ];
    }

    /*-------------------------------------------------------------------------
        Output:
    -------------------------------------------------------------------------*/

    public function appendFormattedElement(XMLElement &$wrapper, $data, $encode = false, $mode = null, $entry_id = null)
    {
        if (self::$compiling == $this->get('id')) {
            return;
        }

        // Only append element to xml output if value is not empty (#32)
        if ('' != $data['value_formatted']) {
            $element = new XMLElement($this->get('element_name'));
            $element->setAttribute('handle', $data['handle']);
            $element->setValue($data['value_formatted']);
            $wrapper->appendChild($element);
        }
    }

    public function prepareTableValue($data, XMLElement $link = null, $entry_id = null)
    {
        if (empty($data)) {
            return;
        }

        return parent::prepareTableValue(
            [
                'value' => $data['value_formatted'],
            ], $link
        );
    }

    /*-------------------------------------------------------------------------
        Export:
    -------------------------------------------------------------------------*/

    /**
     * Return a list of supported export modes for use with `prepareExportValue`.
     *
     * @return array
     */
    public function getExportModes()
    {
        return [
            'getValue' => ExportableField::VALUE,
            'getFormatted' => ExportableField::FORMATTED,
        ];
    }

    /**
     * Give the field some data and ask it to return a value using one of many
     * possible modes.
     *
     * @param mixed $data
     * @param int   $mode
     * @param int   $entry_id
     *
     * @return string|null
     */
    public function prepareExportValue($data, $mode, $entry_id = null)
    {
        $modes = (object) $this->getExportModes();

        if ($mode === $modes->getValue) {
            return $data['value'];
        } elseif ($mode === $modes->getFORMATTED) {
            return $data['value_formatted'];
        }

        return;
    }

    /*-------------------------------------------------------------------------
        Compile:
    -------------------------------------------------------------------------*/

    public function applyFormatting($data)
    {
        if ('none' != $this->get('formatter')) {
            $formatter = TextformatterManager::create($this->get('formatter'));
            $formatted = $formatter->run($data);

            return preg_replace('/&(?![a-z]{0,4}\w{2,3};|#[x0-9a-f]{2,6};)/i', '&amp;', $formatted);
        }

        return;
    }

    public function compile(&$entry)
    {
        self::$compiling = $this->get('id');

        $driver = Symphony::ExtensionManager()->create('reflectionfield');
        $xpath = $driver->getXPath($entry, $this->get('xsltfile'), $this->get('fetch_associated_counts'), $this->get('element_name'));

        self::$compiling = 0;

        $entry_id = $entry->get('id');
        $field_id = $this->get('id');
        $expression = $this->get('expression');
        $replacements = [];

        // Extract xpath queries from the expression
        preg_match_all('/\{[^\}]+\}/', $expression, $matches);

        // Get root node name (will be 'data' if not otherwise defined in a
        // xslt utility)
        $root_node_name = (string) $xpath->evaluate('name(/*)');

        // Find replacements
        // Including modifications for edge-cases (#35)
        foreach ($matches[0] as $match) {

            // Add leading slashes if the expression starts with 'entry'
            // or '/entry'. While these are not valid xpath expressions in
            // the given context, they make the expressions in 2.X backwards
            // compatible with 1.X without causing any problems.
            if (0 === strpos($match, '{entry/')) {
                $path = '//'.trim($match, '{}');
            } elseif (0 === strpos($match, '{/entry/')) {
                $path = '/'.trim($match, '{}');
            }

            // Add a leading '/' if the expression starts directly with the
            // root node name.
            elseif (0 === strpos($match, '{'.$root_node_name)) {
                $path = '/'.trim($match, '{}');
            }

            // Insert a '/' if the expression contains a '(' directly followed
            // by the root node name
            elseif (false !== strpos($match, '('.$root_node_name)) {
                $search = '(';
                $index = strpos($match, $search);
                $string = substr_replace($match, $search.'/', $index, 1);
                $path = trim($string, '{}');
            }

            // Add the name of the root node if the expression only consists of '/'
            elseif ('{/}' === $match) {
                $path = '/'.$root_node_name;
            }

            // Use the given expression without modifications
            else {
                $path = trim($match, '{}');
            }

            // Evaluate the (modified) xpath:
            $result = @$xpath->evaluate('string('.$path.')');

            if (!empty($result)) {
                $replacements[$match] = trim($result);
            } else {
                $replacements[$match] = '';
            }
        }

        // Apply replacements:
        $value = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $expression
        );

        // Apply formatting:
        if (!$value_formatted = $this->applyFormatting($value)) {
            $value_formatted = General::sanitize($value);
        }

        $data = [
            'handle' => Lang::createHandle($value),
            'value' => $value,
            'value_formatted' => $value_formatted,
        ];

        // Save:
        $result = Symphony::Database()->update(
            $data,
            "tbl_entries_data_{$field_id}",
            "`entry_id` = '{$entry_id}'"
        );

        $entry->setData($field_id, $data);
    }

    /*-------------------------------------------------------------------------
        Filtering:
    -------------------------------------------------------------------------*/

    public function buildDSRetrievalSQL($data, &$joins, &$where, $andOperation = false)
    {
        $field_id = $this->get('id');

        if (self::isFilterRegex($data[0])) {
            $this->buildRegexSQL($data[0], ['value', 'handle'], $joins, $where);
        } elseif (preg_match('/^(not-)?(boolean|search):\s*/', $data[0], $matches)) {
            $data = trim(array_pop(explode(':', implode(' + ', $data), 2)));
            $negate = ('' == $matches[1] ? '' : 'NOT');

            if ('' == $data) {
                return true;
            }

            // Negative match?
            if (preg_match('/^not(\W)/i', $data)) {
                $mode = '-';
            } else {
                $mode = '+';
            }

            // Replace ' and ' with ' +':
            $data = preg_replace('/(\W)and(\W)/i', '\\1+\\2', $data);
            $data = preg_replace('/(^)and(\W)|(\W)and($)/i', '\\2\\3', $data);
            $data = preg_replace('/(\W)not(\W)/i', '\\1-\\2', $data);
            $data = preg_replace('/(^)not(\W)|(\W)not($)/i', '\\2\\3', $data);
            $data = preg_replace('/([\+\-])\s*/', '\\1', $mode.$data);

            $data = $this->cleanValue($data);
            ++$this->_key;
            $joins .= "
                LEFT JOIN
                    `tbl_entries_data_{$field_id}` AS t{$field_id}_{$this->_key}
                    ON (e.id = t{$field_id}_{$this->_key}.entry_id)
            ";
            $where .= "
                AND {$negate}(MATCH (t{$field_id}_{$this->_key}.value) AGAINST ('{$data}' IN BOOLEAN MODE))
            ";
        } elseif (preg_match('/^(not-)?((starts|ends)-with|contains):\s*/', $data[0], $matches)) {
            $data = trim(array_pop(explode(':', $data[0], 2)));

            if ('' == $data) {
                return true;
            }

            $negate = ('' == $matches[1] ? '' : 'NOT');
            $data = $this->cleanValue($data);

            if ('ends-with' == $matches[2]) {
                $data = "%{$data}";
            }
            if ('starts-with' == $matches[2]) {
                $data = "{$data}%";
            }
            if ('contains' == $matches[2]) {
                $data = "%{$data}%";
            }

            ++$this->_key;
            $joins .= "
                LEFT JOIN
                    `tbl_entries_data_{$field_id}` AS t{$field_id}_{$this->_key}
                    ON (e.id = t{$field_id}_{$this->_key}.entry_id)
            ";
            $where .= "
                AND {$negate}(
                    t{$field_id}_{$this->_key}.handle LIKE '{$data}'
                    OR t{$field_id}_{$this->_key}.value LIKE '{$data}'
                )
            ";
        } elseif (preg_match('/^(?:equal to or )?(?:less than|more than|equal to) -?\d+(?:\.\d+)?$/i', $data[0])) {
            $comparisons = [];
            foreach ($data as $string) {
                if (preg_match('/^(equal to or )?(less than|more than|equal to) (-?\d+(?:\.\d+)?)$/i', $string, $matches)) {
                    $number = trim($matches[3]);
                    if (!is_numeric($number) || '' === $number) {
                        continue;
                    }
                    $number = floatval($number);

                    $operator = '<';
                    switch ($matches[2]) {
                        case 'more than': $operator = '>'; break;
                        case 'less than': $operator = '<'; break;
                        case 'equal to': $operator = '='; break;
                    }

                    if ('equal to or ' == $matches[1] && '=' != $operator) {
                        $operator .= '=';
                    }

                    $comparisons[] = "{$operator} {$number}";
                }
            }

            if (!empty($comparisons)) {
                ++$this->_key;
                $joins .= "
                    LEFT JOIN
                        `tbl_entries_data_{$field_id}` AS t{$field_id}_{$this->_key}
                        ON (e.id = t{$field_id}_{$this->_key}.entry_id)
                ";

                $value = " t{$field_id}_{$this->_key}.value ";
                $comparisons = $value.implode(' '.($andOperation ? 'AND' : 'OR').$value, $comparisons);

                $where .= "
                    AND (
                        {$comparisons}
                    )
                ";
            }
        } elseif ($andOperation) {
            foreach ($data as $value) {
                ++$this->_key;
                $value = $this->cleanValue($value);
                $joins .= "
                    LEFT JOIN
                        `tbl_entries_data_{$field_id}` AS t{$field_id}_{$this->_key}
                        ON (e.id = t{$field_id}_{$this->_key}.entry_id)
                ";
                $where .= "
                    AND (
                        t{$field_id}_{$this->_key}.handle = '{$value}'
                        OR t{$field_id}_{$this->_key}.value = '{$value}'
                    )
                ";
            }
        } else {
            if (!is_array($data)) {
                $data = [$data];
            }

            foreach ($data as &$value) {
                $value = $this->cleanValue($value);
            }

            ++$this->_key;
            $data = implode("', '", $data);
            $joins .= "
                LEFT JOIN
                    `tbl_entries_data_{$field_id}` AS t{$field_id}_{$this->_key}
                    ON (e.id = t{$field_id}_{$this->_key}.entry_id)
            ";
            $where .= "
                AND (
                    t{$field_id}_{$this->_key}.handle IN ('{$data}')
                    OR t{$field_id}_{$this->_key}.value IN ('{$data}')
                )
            ";
        }

        return true;
    }

    /*-------------------------------------------------------------------------
        Sorting:
    -------------------------------------------------------------------------*/

    public function buildSortingSQL(&$joins, &$where, &$sort, $order = 'ASC')
    {
        if (in_array(strtolower($order), ['random', 'rand'])) {
            $sort = 'ORDER BY RAND()';
        } else {
            $sort = sprintf(
                'ORDER BY (
                    SELECT %s
                    FROM tbl_entries_data_%d AS `ed`
                    WHERE entry_id = e.id
                ) %s',
                '`ed`.value',
                $this->get('id'),
                $order
            );
        }
    }

    /*-------------------------------------------------------------------------
        Grouping:
    -------------------------------------------------------------------------*/

    public function groupRecords($records)
    {
        if (!is_array($records) or empty($records)) {
            return;
        }

        $element = $this->get('element_name');

        $groups = [
            $element => [],
        ];

        foreach ($records as $record) {
            $data = $record->getData($this->get('id'));
            $handle = $data['handle'];

            if (!isset($groups[$element][$handle])) {
                $groups[$element][$handle] = [
                    'attr' => [
                        'handle' => $handle,
                        'value' => $data['value_formatted'],
                    ],
                    'records' => [],
                    'groups' => [],
                ];
            }

            $groups[$element][$handle]['records'][] = $record;
        }

        return $groups;
    }
}
