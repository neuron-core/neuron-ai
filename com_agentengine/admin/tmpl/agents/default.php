<?php
defined('_JEXEC') or die;
?>
<form action="index.php?option=com_agentengine&view=agents" method="post" name="adminForm" id="adminForm">
    <div class="row">
        <div class="col-md-12">
            <div class="j-main-container">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th width="1%" class="text-center">
                                <input type="checkbox" name="checkall-toggle" value="" title="<?php echo JText::_('JGLOBAL_CHECK_ALL'); ?>" onclick="Joomla.checkAll(this)" />
                            </th>
                            <th class="title">
                                <?php echo JText::_('COM_AGENTENGINE_AGENT_NAME_LABEL'); ?>
                            </th>
                            <th class="d-none d-md-table-cell">
                                <?php echo JText::_('COM_AGENTENGINE_AGENT_DESCRIPTION_LABEL'); ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($this->items as $i => $item) : ?>
                            <tr class="row<?php echo $i % 2; ?>">
                                <td class="text-center">
                                    <?php echo JHtml::_('grid.id', $i, $item->id); ?>
                                </td>
                                <td>
                                    <a href="<?php echo JRoute::_('index.php?option=com_agentengine&task=agent.edit&id=' . (int) $item->id); ?>">
                                        <?php echo $this->escape($item->name); ?>
                                    </a>
                                </td>
                                <td class="d-none d-md-table-cell">
                                    <?php echo $this->escape($item->description); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php echo $this->pagination->getListFooter(); ?>
            </div>
        </div>
    </div>
    <input type="hidden" name="task" value="" />
    <input type="hidden" name="boxchecked" value="0" />
    <?php echo JHtml::_('form.token'); ?>
</form>
