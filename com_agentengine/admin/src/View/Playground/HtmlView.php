<?php
namespace Jules\Component\AgentEngine\Administrator\View\Playground;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Language\Text;

class HtmlView extends BaseHtmlView
{
    public function display($tpl = null)
    {
        $this->item = $this->get('Item');

        $this->addToolbar();

        return parent::display($tpl);
    }

    protected function addToolbar()
    {
        ToolbarHelper::title(Text::_('COM_AGENTENGINE_PLAYGROUND_TITLE') . ': ' . $this->item->name);
        ToolbarHelper::cancel('playground.cancel');
    }
}
