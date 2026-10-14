<?php

declare(strict_types=1);

namespace BrewPHP\Tests\Unit\Templates;

use BrewPHP\Templates\TemplateError;
use BrewPHP\Templates\TemplateNotFound;
use BrewPHP\Templates\Templates;

final class InheritanceTest extends TemplateTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->write([
            'base.html' => '<title>{% block title %}Site{% endblock %}</title>'
                . '<main>{% block content %}{% endblock content %}</main>'
                . '<footer>{% block footer %}(c) {{ year }}{% endblock %}</footer>',
            'blog/base.html' => "{% extends 'base.html' %}"
                . '{% block title %}Blog - {{ block.super }}{% endblock %}'
                . '{% block content %}<div class="blog">{% block post %}no post{% endblock %}</div>{% endblock %}',
            'blog/post.html' => "{% extends 'blog/base.html' %}\nignored text\n"
                . '{% block title %}{{ title }} | {{ block.super }}{% endblock %}'
                . '{% block post %}<h1>{{ title }}</h1>{% endblock %}',
        ]);
    }

    public function testBlocksAndBlockSuper(): void
    {
        self::assertSame(
            '<title>Site</title><main></main><footer>(c) 2026</footer>',
            $this->templates()->render('base.html', ['year' => 2026]),
        );
        self::assertSame(
            '<title>A &amp; B | Blog - Site</title><main><div class="blog"><h1>A &amp; B</h1></div></main><footer>(c) 2026</footer>',
            $this->templates()->render('blog/post.html', ['title' => 'A & B', 'year' => 2026]),
        );
        self::assertSame(
            '<title>Blog - Site</title><main><div class="blog">no post</div></main><footer>(c) </footer>',
            $this->templates()->render('blog/base.html'),
        );
    }

    public function testExtendsWithAVariable(): void
    {
        $this->write(['page.html' => '{% extends layout %}{% block content %}page{% endblock %}']);

        self::assertSame('<title>Site</title><main>page</main><footer>(c) </footer>', $this->templates()->render('page.html', ['layout' => 'base.html']));
    }

    public function testBlocksInsideTagsAndLoopsInBlocks(): void
    {
        $this->write([
            'list.html' => "{% extends 'base.html' %}{% block content %}{% for x in items %}{% block item %}{{ x }}{% endblock %}{% endfor %}{% endblock %}",
            'list2.html' => "{% extends 'list.html' %}{% block item %}[{{ x }}|{{ block.super }}]{% endblock %}",
        ]);

        self::assertSame('<title>Site</title><main>[1|1][2|2]</main><footer>(c) </footer>', $this->templates()->render('list2.html', ['items' => [1, 2]]));
    }

    public function testAProjectTemplateCanExtendTheTemplateItOverrides(): void
    {
        $app = $this->tempDir();
        $this->write(['admin/base.html' => '<h1>{% block branding %}Admin{% endblock %}</h1>'], $app);
        $this->write(['admin/base.html' => "{% extends 'admin/base.html' %}{% block branding %}My site {{ block.super }}{% endblock %}"]);
        $templates = new Templates([$this->dir, $app], $this->cache);

        self::assertSame('<h1>My site Admin</h1>', $templates->render('admin/base.html'));
    }

    public function testExtendsLoopIsDetected(): void
    {
        $this->write(['a.html' => "{% extends 'b.html' %}", 'b.html' => "{% extends 'a.html' %}"]);

        $this->expectException(TemplateError::class);
        $this->expectExceptionMessage('extends itself');
        $this->templates()->render('a.html');
    }

    public function testMissingParent(): void
    {
        $this->write(['orphan.html' => "{% extends 'nowhere.html' %}"]);

        try {
            $this->templates()->render('orphan.html');
            self::fail('Expected an error');
        } catch (TemplateError $e) {
            self::assertInstanceOf(TemplateNotFound::class, $e->getPrevious());
            self::assertSame('orphan.html', $e->templateName);
            self::assertSame(1, $e->templateLine);
        }
    }

    public function testInclude(): void
    {
        $this->write([
            'partials/greeting.html' => 'Hello {{ name }}{% if punctuation %}{{ punctuation }}{% endif %}',
            'page.html' => "{% include 'partials/greeting.html' %}|{% include 'partials/greeting.html' with name='Bob' punctuation='!' %}"
                . "|{% include 'partials/greeting.html' with punctuation='?' only %}|{{ punctuation }}",
        ]);

        self::assertSame('Hello Ann|Hello Bob!|Hello ?|', $this->templates()->render('page.html', ['name' => 'Ann']));
    }

    public function testIncludeWithVariableNameOrList(): void
    {
        $this->write(['b.html' => 'B{{ x }}']);
        $templates = $this->templates();

        self::assertSame('B1', $templates->renderString('{% include tpl %}', ['tpl' => 'b.html', 'x' => 1]));
        self::assertSame('B2', $templates->renderString('{% include names %}', ['names' => ['missing.html', 'b.html'], 'x' => 2]));
    }

    public function testIncludedTemplatesHaveTheirOwnBlocksAndForloop(): void
    {
        $this->write([
            'row.html' => '{% block cell %}<td>{{ item }}:{{ forloop.counter }}</td>{% endblock %}',
            'table.html' => "{% extends 'base.html' %}{% block content %}{% for item in items %}{% include 'row.html' %}{% endfor %}{% endblock %}",
        ]);

        self::assertSame('<title>Site</title><main><td>a:1</td><td>b:2</td></main><footer>(c) </footer>', $this->templates()->render('table.html', ['items' => ['a', 'b']]));
    }

    public function testIncludedTemplatesAreEscapedOnTheirOwn(): void
    {
        $this->write(['inner.html' => '{{ html }}']);

        self::assertSame('&lt;b&gt;', $this->templates()->renderString("{% autoescape off %}{% include 'inner.html' %}{% endautoescape %}", ['html' => '<b>']));
    }

    public function testPhpTemplatesCannotBeIncludedOrExtended(): void
    {
        $this->write(['secret.php' => '<?php $password = "x";']);

        foreach (["{% include 'secret.php' %}", "{% extends 'secret.php' %}"] as $source) {
            try {
                $this->templates()->renderString($source);
                self::fail('Expected an error for ' . $source);
            } catch (TemplateError $e) {
                self::assertStringContainsString('is a PHP template', $e->getMessage());
                self::assertStringNotContainsString('password', $e->getMessage());
            }
        }
    }

    public function testMissingInclude(): void
    {
        try {
            $this->templates()->renderString("line 1\n\n{% include 'nope.html' %}");
            self::fail('Expected an error');
        } catch (TemplateError $e) {
            self::assertInstanceOf(TemplateNotFound::class, $e->getPrevious());
            self::assertSame(3, $e->templateLine);
            self::assertStringContainsString('Template "nope.html" not found', $e->getMessage());
        }
    }

    public function testErrorsInsideIncludesPointAtTheIncludedTemplate(): void
    {
        $this->write(['broken.html' => "fine\n{{ x|date:1|nope }}", 'outer.html' => "{% include 'broken.html' %}"]);

        try {
            $this->templates()->render('outer.html');
            self::fail('Expected a syntax error');
        } catch (TemplateError $e) {
            self::assertSame('broken.html', $e->templateName);
            self::assertSame(2, $e->templateLine);
        }
    }
}
