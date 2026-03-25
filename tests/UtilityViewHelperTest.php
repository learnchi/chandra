<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Support\Utility;

require_once __DIR__ . '/../vendor/autoload.php';

final class UtilityViewHelperTest extends TestCase
{
    /**
     * HTML 本文に危険な文字列を埋めても、タグやコメントとして解釈されず
     * すべてテキストとして安全に表示されることを確認する。
     */
    public function testHEscapesDangerousStringForHtmlBodyContent(): void
    {
        $raw = '<script>alert("xss")</script><!-- comment --> O\'Reilly & "quoted" <b>bold</b>';

        $escaped = Utility::h($raw);
        $html = '<p>' . $escaped . '</p>';

        $this->assertSame(
            '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;&lt;!-- comment --&gt; O&#039;Reilly &amp; &quot;quoted&quot; &lt;b&gt;bold&lt;/b&gt;',
            $escaped
        );
        $this->assertSame(
            '<p>&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;&lt;!-- comment --&gt; O&#039;Reilly &amp; &quot;quoted&quot; &lt;b&gt;bold&lt;/b&gt;</p>',
            $html
        );
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('</script>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;!-- comment --&gt;', $html);
    }

    /**
     * input の value 属性に危険な文字列を入れても属性が途中で閉じず、
     * 追加属性の注入につながらない形で 1 つの value に収まることを確認する。
     */
    public function testHEscapesDangerousStringForAttributeValue(): void
    {
        $raw = "\" autofocus onfocus=\"alert('xss')\" data-test=\"<tag>\" & 'single-quote'";

        $escaped = Utility::h($raw);
        $html = '<input type="text" name="title" value="' . $escaped . '">';

        $this->assertSame(
            '&quot; autofocus onfocus=&quot;alert(&#039;xss&#039;)&quot; data-test=&quot;&lt;tag&gt;&quot; &amp; &#039;single-quote&#039;',
            $escaped
        );
        $this->assertSame(
            '<input type="text" name="title" value="&quot; autofocus onfocus=&quot;alert(&#039;xss&#039;)&quot; data-test=&quot;&lt;tag&gt;&quot; &amp; &#039;single-quote&#039;">',
            $html
        );
        $this->assertSame(1, preg_match('/^<input type="text" name="title" value="[^"]*">$/', $html));
        $this->assertSame(1, substr_count($html, 'value="'));
    }

    /**
     * textarea 内に閉じタグや script を含む文字列を入れても、
     * textarea を途中で抜けずに内容全体がテキスト化されることを確認する。
     */
    public function testHEscapesDangerousStringForTextareaContent(): void
    {
        $raw = "first line\n</textarea><script>alert(\"xss\")</script>&'\"\nlast line";

        $escaped = Utility::h($raw);
        $html = '<textarea name="memo">' . $escaped . '</textarea>';

        $this->assertSame(
            "first line\n&lt;/textarea&gt;&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;&amp;&#039;&quot;\nlast line",
            $escaped
        );
        $this->assertSame(
            "<textarea name=\"memo\">first line\n&lt;/textarea&gt;&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;&amp;&#039;&quot;\nlast line</textarea>",
            $html
        );
        $this->assertSame(1, substr_count($html, '<textarea'));
        $this->assertSame(1, substr_count($html, '</textarea>'));
        $this->assertStringNotContainsString('</textarea><script>', $html);
        $this->assertStringContainsString('&lt;/textarea&gt;', $html);
    }

    /**
     * 既にエスケープ済みに見える文字列も再度そのまま文字列として扱われ、
     * 実タグや既存エンティティとして解釈されないことを確認する。
     */
    public function testHEscapesEntityLikeInputAsPlainText(): void
    {
        $raw = '&lt;strong&gt;safe?&lt;/strong&gt; &amp; already-escaped';

        $escaped = Utility::h($raw);
        $html = '<div>' . $escaped . '</div><textarea>' . $escaped . '</textarea>';

        $this->assertSame(
            '&amp;lt;strong&amp;gt;safe?&amp;lt;/strong&amp;gt; &amp;amp; already-escaped',
            $escaped
        );
        $this->assertStringContainsString('&amp;lt;strong&amp;gt;', $html);
        $this->assertStringNotContainsString('<strong>', $html);
        $this->assertStringNotContainsString('&lt;strong&gt;', $html);
    }

    /**
     * null や各種 scalar 値も安全に文字列化され、
     * テンプレート出力にそのまま使える戻り値になることを確認する。
     */
    public function testHCastsScalarAndNullValuesToSafeStrings(): void
    {
        $this->assertSame('', Utility::h(null));
        $this->assertSame('0', Utility::h(0));
        $this->assertSame('1', Utility::h(true));
        $this->assertSame('', Utility::h(false));
        $this->assertSame('3.14', Utility::h(3.14));
    }
}
