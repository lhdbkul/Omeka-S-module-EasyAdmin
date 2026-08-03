<?php declare(strict_types=1);

namespace EasyAdmin\Stdlib;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Omeka\Form\Element\ArrayTextarea;
use Omeka\Form\Element\Asset;
use Omeka\Form\Element\BrowseDefaults;
use Omeka\Form\Element\ColorPicker;
use Omeka\Form\Element\RestoreTextarea;

/**
 * Classify settings as structural (configuration), literal (free text) or
 * manual, from the type of their form element.
 *
 * This mirrors the classification of the SiteHub module, so the "text fields"
 * filter of the settings page uses the same mechanism, without depending on
 * SiteHub (which is optional and only present on the site settings page).
 */
class SettingKindClassifier
{
    const STRUCTURAL = 'structural';
    const LITERAL = 'literal';
    const MANUAL = 'manual';

    /**
     * Map every element name of a form/fieldset to its kind.
     *
     * @return array<string, string>
     */
    public function classify(Fieldset $fieldset): array
    {
        $map = [];
        $this->walk($fieldset, $map);
        return $map;
    }

    protected function walk(Fieldset $fieldset, array &$map): void
    {
        foreach ($fieldset->getElements() as $element) {
            $name = $element->getName();
            if ($name !== '' && $name !== null) {
                $map[(string) $name] = $this->kindOf($element);
            }
        }
        foreach ($fieldset->getFieldsets() as $child) {
            $this->walk($child, $map);
        }
    }

    protected function kindOf(Element $element): string
    {
        $syncType = $element->getOption('sync_type');
        if (in_array($syncType, [self::STRUCTURAL, self::LITERAL, self::MANUAL], true)) {
            return $syncType;
        }
        $translatable = $element->getOption('translatable');
        if ($translatable === true) {
            return self::LITERAL;
        }
        if ($translatable === false) {
            return self::STRUCTURAL;
        }

        if ($element instanceof Element\Password) {
            return self::MANUAL;
        }
        // A note is a static text without value: it is not a text setting.
        if ($element->getAttribute('type') === 'note') {
            return self::STRUCTURAL;
        }
        // Some elements extend Text/Textarea but hold a configuration value
        // (colour, array, ini, url query…), so they are checked before the text
        // types below. ArrayTextarea also covers its subclasses (DataTextarea,
        // ArrayQueriesTextarea, GroupTextarea). The Common elements are matched
        // by fully qualified name, harmless when Common is absent.
        if ($element instanceof Element\Select
            || $element instanceof Element\MultiCheckbox
            || $element instanceof Element\Checkbox
            || $element instanceof Element\Radio
            || $element instanceof Element\Number
            || $element instanceof Asset
            || $element instanceof BrowseDefaults
            || $element instanceof ColorPicker
            || $element instanceof RestoreTextarea
            || $element instanceof ArrayTextarea
            || $element instanceof Element\AbstractDateTime
            || $element instanceof Element\MonthSelect
            || $element instanceof Element\Color
            || $element instanceof Element\File
            || $element instanceof Element\Email
            || $element instanceof Element\Url
            || $element instanceof \Omeka\Form\Element\Columns
            || $element instanceof \Omeka\Form\Element\Query
            || $element instanceof \Common\Form\Element\IniTextarea
            || $element instanceof \Common\Form\Element\ArrayText
            || $element instanceof \Common\Form\Element\UrlQuery
        ) {
            return self::STRUCTURAL;
        }
        // Email and url are typed addresses, not translatable prose, so they
        // are classified as non-text above, leaving genuine free text here.
        if ($element instanceof Element\Textarea
            || $element instanceof Element\Text
        ) {
            return self::LITERAL;
        }

        // Ambiguous custom element: treat as free text.
        return self::LITERAL;
    }
}
