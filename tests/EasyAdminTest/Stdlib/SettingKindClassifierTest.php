<?php declare(strict_types=1);

namespace EasyAdminTest\Stdlib;

use EasyAdmin\Stdlib\SettingKindClassifier;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Omeka\Form\Element\ColorPicker;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the classification of the settings by kind.
 *
 * The classifier is called directly: a test rewriting its rules would keep
 * passing after the rules changed.
 *
 * @covers \EasyAdmin\Stdlib\SettingKindClassifier
 */
class SettingKindClassifierTest extends TestCase
{
    /**
     * @var \EasyAdmin\Stdlib\SettingKindClassifier
     */
    protected $classifier;

    protected function setUp(): void
    {
        $this->classifier = new SettingKindClassifier();
    }

    protected function classifyOne(Element $element): string
    {
        $fieldset = new Fieldset();
        $fieldset->add($element->setName('setting'));
        return $this->classifier->classify($fieldset)['setting'];
    }

    /**
     * A free text setting is translatable, so it is literal.
     *
     * @dataProvider provideLiteralElements
     */
    public function testFreeTextIsLiteral(Element $element): void
    {
        $this->assertSame(SettingKindClassifier::LITERAL, $this->classifyOne($element));
    }

    public function provideLiteralElements(): array
    {
        return [
            'text' => [new Element\Text()],
            'textarea' => [new Element\Textarea()],
        ];
    }

    /**
     * A configuration setting is not translatable, so it is structural.
     *
     * @dataProvider provideStructuralElements
     */
    public function testConfigurationIsStructural(Element $element): void
    {
        $this->assertSame(SettingKindClassifier::STRUCTURAL, $this->classifyOne($element));
    }

    public function provideStructuralElements(): array
    {
        return [
            'select' => [new Element\Select()],
            'checkbox' => [new Element\Checkbox()],
            'multi checkbox' => [new Element\MultiCheckbox()],
            'radio' => [new Element\Radio()],
            'number' => [new Element\Number()],
            'color' => [new Element\Color()],
            'file' => [new Element\File()],
            // An address is typed, not translatable prose.
            'email' => [new Element\Email()],
            'url' => [new Element\Url()],
        ];
    }

    /**
     * ColorPicker extends Text but holds a configuration value, so the order of
     * the checks matters.
     */
    public function testColorPickerIsStructuralAlthoughItExtendsText(): void
    {
        $this->assertInstanceOf(Element\Text::class, new ColorPicker());
        $this->assertSame(
            SettingKindClassifier::STRUCTURAL,
            $this->classifyOne(new ColorPicker())
        );
    }

    public function testPasswordIsManual(): void
    {
        $this->assertSame(
            SettingKindClassifier::MANUAL,
            $this->classifyOne(new Element\Password())
        );
    }

    /**
     * A note is a static text without value: it is not a text setting.
     */
    public function testNoteIsStructural(): void
    {
        $element = new Element\Text();
        $element->setAttribute('type', 'note');
        $this->assertSame(SettingKindClassifier::STRUCTURAL, $this->classifyOne($element));
    }

    /**
     * An unknown element is ambiguous, so it is treated as free text.
     */
    public function testUnknownElementIsLiteral(): void
    {
        $this->assertSame(
            SettingKindClassifier::LITERAL,
            $this->classifyOne(new Element\Hidden())
        );
    }

    // The options of the element win over the type.

    public function testSyncTypeOptionWins(): void
    {
        $element = new Element\Text();
        $element->setOption('sync_type', SettingKindClassifier::STRUCTURAL);
        $this->assertSame(SettingKindClassifier::STRUCTURAL, $this->classifyOne($element));
    }

    public function testInvalidSyncTypeIsIgnored(): void
    {
        $element = new Element\Text();
        $element->setOption('sync_type', 'whatever');
        $this->assertSame(SettingKindClassifier::LITERAL, $this->classifyOne($element));
    }

    public function testTranslatableOptionWins(): void
    {
        $structural = new Element\Checkbox();
        $structural->setOption('translatable', true);
        $this->assertSame(SettingKindClassifier::LITERAL, $this->classifyOne($structural));

        $literal = new Element\Text();
        $literal->setOption('translatable', false);
        $this->assertSame(SettingKindClassifier::STRUCTURAL, $this->classifyOne($literal));
    }

    // Walk of the form.

    public function testNestedFieldsetsAreClassified(): void
    {
        $fieldset = new Fieldset();
        $fieldset->add((new Element\Text())->setName('title'));
        $child = new Fieldset('child');
        $child->add((new Element\Checkbox())->setName('enabled'));
        $fieldset->add($child);

        $this->assertSame(
            ['title' => SettingKindClassifier::LITERAL, 'enabled' => SettingKindClassifier::STRUCTURAL],
            $this->classifier->classify($fieldset)
        );
    }

    public function testEmptyFormGivesEmptyMap(): void
    {
        $this->assertSame([], $this->classifier->classify(new Fieldset()));
    }
}
