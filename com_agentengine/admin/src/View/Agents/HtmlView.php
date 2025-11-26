<?php
namespace Jules\Component\AgentEngine\Administrator\View\Agents;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Language\Text;

class HtmlView extends BaseHtmlView
{
    public function display($tpl = null)
    {
        $this->items = $this->get('Items');
        $this->pagination = $this->get('Pagination');
        $this->state = $this->get('State');

        $this->addToolbar();

        return parent::display($tpl);
    }

    protected function addToolbar()
    {
        ToolbarHelper::title(Text::_('COM_AGENTENGINE_AGENTS_TITLE'));
        ToolbarHelper::addNew('agent.add');
        ToolbarHelper::editList('agent.edit');
        ToolbarHelper::deleteList('', 'agents.delete');
    }
}
