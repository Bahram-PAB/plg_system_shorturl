<?php
/**
 * System - Short URL Plugin for Joomla 6
 * کوتاه‌کننده لینک مطالب جوملا
 *
 * ponytail: legacy unwrapped event args (removed in J7). migrate to
 * SubscriberInterface + typed events when targeting J7+.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Language\Text;

class PlgSystemShorturl extends CMSPlugin
{
    protected $autoloadLanguage = true;

    // ------------------------------------------------------------------
    // Events
    // ------------------------------------------------------------------

    /**
     * Intercept ?s= parameter and redirect to the original article.
     */
    public function onAfterInitialise()
    {
        $app = Factory::getApplication();

        if ($app->isClient('administrator')) {
            return;
        }

        $shortCode = $app->input->getString('s', '');

        if ($shortCode !== '') {
            $this->redirectFromShortUrl($shortCode);
        }
    }

    /**
     * Inject the short-URL field + visible JS banner into the article edit form,
     * and inject a Help tab into the plugin settings page.
     */
    public function onContentPrepareForm($form, $data)
    {
        if (!($form instanceof \Joomla\CMS\Form\Form)) {
            return;
        }

        $formName = $form->getName();

        // --- Article edit form ---
        if ($formName === 'com_content.article') {
            $formPath = JPATH_PLUGINS . '/system/shorturl/forms/article.xml';

            if (is_file($formPath)) {
                $form->loadFile($formPath);
            }

            Factory::getDocument()->addScriptDeclaration($this->getShortUrlTabJs());
            return;
        }

        // Help tab is rendered natively via a fieldset in shorturl.xml.
    }

    /**
     * Prepend a styled Short-URL bar to the article content on the frontend.
     * Appears right above the article text, below the Details/metadata section.
     */
    public function onContentPrepare($context, &$article, &$params, $page = 0)
    {
        if ($context !== 'com_content.article' || !isset($article->id)) {
            return;
        }

        $app = Factory::getApplication();
        if ($app->isClient('administrator')) {
            return;
        }

        $shortCode = $this->getShortCodeForArticle((int) $article->id);

        if (!$shortCode) {
            return;
        }

        $escaped = htmlspecialchars(Uri::root() . '?s=' . $shortCode, ENT_QUOTES, 'UTF-8');
        $copyLabel = Text::_('PLG_SYSTEM_SHORTURL_COPY');

        $bar = '<div class="short-url-bar" style="background:#e7f3ff;border:1px solid #b8daff;border-radius:6px;padding:8px 14px;margin:0 0 16px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:14px;">'
            . '<strong style="white-space:nowrap;">Short Url:</strong> '
            . '<a href="' . $escaped . '" target="_blank" rel="noopener" style="word-break:break-all;">' . $escaped . '</a> '
            . '<button type="button" '
            . 'onclick="var a=this.closest(\'.short-url-bar\').querySelector(\'a\');navigator.clipboard.writeText(a.href);this.textContent=\'✓\';var s=this;setTimeout(function(){s.textContent=\'' . $copyLabel . '\'},1500)" '
            . 'style="border:1px solid #0d6efd;background:#fff;color:#0d6efd;border-radius:4px;padding:2px 10px;cursor:pointer;font-size:13px;white-space:nowrap;">'
            . $copyLabel . '</button></div>';

        $article->text = $bar . $article->text;
    }

    /**
     * After an article is saved, ensure a short code exists.
     */
    public function onContentAfterSave($context, $article, $isNew)
    {
        if ($context !== 'com_content.article') {
            return;
        }

        $db  = Factory::getDbo();
        $aid = (int) $article->id;

        // Already has a short code? Keep it — never regenerate.
        $existing = $this->getShortCodeForArticle($aid);

        if ($existing) {
            $shortCode = $existing;
        } else {
            $shortCode = $this->generateShortCode($db);
            $this->insertShortCode($aid, $shortCode);
        }

        // Show the short URL in a system message.
        if ($shortCode) {
            $shortUrl = Uri::root() . '?s=' . $shortCode;
            $escaped  = htmlspecialchars($shortUrl, ENT_QUOTES, 'UTF-8');
            $label    = Text::_('PLG_SYSTEM_SHORTURL_SHORT_URL');
            $copyBtn  = Text::_('PLG_SYSTEM_SHORTURL_COPY');

            Factory::getApplication()->enqueueMessage(
                '<strong>🔗 ' . $label . ':</strong> '
                . '<a href="' . $escaped . '" target="_blank" rel="noopener">' . $escaped . '</a> '
                . '<button type="button" class="btn btn-sm btn-outline-secondary" '
                . 'onclick="navigator.clipboard.writeText(this.previousElementSibling.href);this.textContent=\'✓\';var s=this;setTimeout(function(){s.textContent=\'' . $copyBtn . '\'},1500)">'
                . $copyBtn . '</button>',
                'info',
                false // raw HTML — we need <a> + <button>
            );
        }
    }

    /**
     * Populate the short_url field + auto-generate for existing articles.
     */
    public function onContentPrepareData($context, $article)
    {
        if ($context !== 'com_content.article' || !isset($article->id)) {
            return;
        }

        $db   = Factory::getDbo();
        $aid  = (int) $article->id;

        $shortCode = $this->getShortCodeForArticle($aid);

        // Existing article without a short code → generate now.
        if (!$shortCode) {
            $shortCode = $this->generateShortCode($db);
            $this->insertShortCode($aid, $shortCode);
        }

        $article->short_url = $shortCode ? Uri::root() . '?s=' . $shortCode : '';
    }

    // ------------------------------------------------------------------
    // Database helpers
    // ------------------------------------------------------------------

    private function getShortCodeForArticle(int $articleId): ?string
    {
        $db = Factory::getDbo();

        $query = $db->getQuery(true)
            ->select('short_code')
            ->from('#__shorturl')
            ->where('article_id = ' . $articleId);

        return $db->setQuery($query)->loadResult() ?: null;
    }

    private function insertShortCode(int $articleId, string $shortCode): void
    {
        $db  = Factory::getDbo();
        $now = $db->quote(date('Y-m-d H:i:s'));

        $query = $db->getQuery(true)
            ->insert('#__shorturl')
            ->columns('article_id, short_code, created, modified')
            ->values($articleId . ', ' . $db->quote($shortCode) . ', ' . $now . ', ' . $now);

        try {
            $db->setQuery($query)->execute();
        } catch (\RuntimeException $e) {
            // Concurrency collision — safe to ignore.
        }
    }

    /**
     * Generate a unique short code.
     *
     * When prefix is set, the total length = Code length param
     * (prefix + random = length).
     * When prefix is empty, random chars fill the entire length.
     */
    private function generateShortCode(\Joomla\Database\DatabaseDriver $db): string
    {
        $length  = (int) $this->params->get('length', 6);
        $prefix  = (string) $this->params->get('prefix', '');
        $charset = (string) $this->params->get('charset', 'alnum');

        $chars = match ($charset) {
            'alpha'   => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ',
            'numeric' => '0123456789',
            default   => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
        };

        $prefixLen = strlen($prefix);
        $randomLen = max(1, $length - $prefixLen);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = $prefix;
            for ($i = 0; $i < $randomLen; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }

            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from('#__shorturl')
                ->where('short_code = ' . $db->quote($code));

            if ((int) $db->setQuery($query)->loadResult() === 0) {
                return $code;
            }
        }

        // Fallback — practically impossible to collide.
        return $prefix . substr(md5(uniqid((string) mt_rand(), true)), 0, $randomLen);
    }

    /**
     * Redirect to the original article (only if published).
     */
    private function redirectFromShortUrl(string $shortCode): void
    {
        $db = Factory::getDbo();

        $query = $db->getQuery(true)
            ->select('article_id')
            ->from('#__shorturl')
            ->where('short_code = ' . $db->quote($shortCode));

        $articleId = (int) $db->setQuery($query)->loadResult();

        if ($articleId === 0) {
            return;
        }

        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from('#__content')
            ->where('id = ' . $articleId)
            ->where('state = 1');

        if ((int) $db->setQuery($query)->loadResult() === 0) {
            return;
        }

        $link = Route::_('index.php?option=com_content&view=article&id=' . $articleId);
        Factory::getApplication()->redirect($link);
    }

    // ------------------------------------------------------------------
    // JavaScript injections
    // ------------------------------------------------------------------

    /**
     * Render short URL inside the "Short URL" tab (attrib-shorturl).
     * Reads the value from the hidden field populated by onContentPrepareData.
     */
    private function getShortUrlTabJs(): string
    {
        $copyLabel  = addslashes(Text::_('PLG_SYSTEM_SHORTURL_COPY'));
        $pendingMsg = addslashes(Text::_('PLG_SYSTEM_SHORTURL_PENDING'));
        $label      = addslashes('Short URL');

        return <<<JS
(function(){
function render(){
  var field=document.querySelector('[name="jform[short_url]"]');
  var pane=document.getElementById('attrib-shorturl');
  if(!pane)return;
  var url=field?field.value:'';
  if(!url){
    pane.innerHTML='<div style="border:1px dashed #ccc;border-radius:8px;padding:18px;margin:10px;text-align:center;color:#999;font-size:14px;">{$pendingMsg}</div>';
    return;
  }
  var box=document.createElement('div');
  box.style.cssText='border:2px solid #dee2e6;border-radius:8px;padding:18px 14px;margin:10px;position:relative;';
  var lbl=document.createElement('div');
  lbl.style.cssText='position:absolute;top:-11px;left:12px;background:#fff;padding:0 6px;font-weight:600;color:#666;font-size:13px;';
  lbl.textContent='{$label}';
  box.appendChild(lbl);
  var a=document.createElement('a');a.href=url;a.textContent=url;a.target='_blank';a.rel='noopener';
  a.style.cssText='word-break:break-all;color:#0d6efd;text-decoration:none;font-size:14px;';
  a.onmouseenter=function(){this.style.textDecoration='underline';};
  a.onmouseleave=function(){this.style.textDecoration='none';};
  box.appendChild(a);
  var btn=document.createElement('button');btn.type='button';btn.textContent='{$copyLabel}';
  btn.style.cssText='border:1px solid #0d6efd;background:#fff;color:#0d6efd;border-radius:4px;padding:3px 12px;cursor:pointer;font-size:13px;white-space:nowrap;vertical-align:middle;margin-left:8px;';
  btn.onclick=function(){navigator.clipboard.writeText(url);this.textContent='\\u2713';var s=this;setTimeout(function(){s.textContent='{$copyLabel}';},1500);};
  box.appendChild(btn);
  pane.innerHTML='';pane.appendChild(box);
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',render);else render();
})();
JS;
    }

}
