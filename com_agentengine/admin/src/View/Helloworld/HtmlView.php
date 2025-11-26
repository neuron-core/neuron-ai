<?php
namespace Jules\Component\AgentEngine\Administrator\View\Helloworld;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

class HtmlView extends BaseHtmlView
{
    public string $greeting = 'Hello, World! 👋';

    public function display($tpl = null)
    {
        return parent::display($tpl);
    }
}
