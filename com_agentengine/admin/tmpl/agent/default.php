<?php
defined('_JEXEC') or die;

JHtml::_('behavior.formvalidation');
?>
<form action="<?php echo JRoute::_('index.php?option=com_agentengine&layout=edit&id=' . (int) $this->item->id); ?>" method="post" name="adminForm" id="agent-form" class="form-validate">
    <div class="form-horizontal">
        <div class="row">
            <div class="col-lg-9">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title"><?php echo JText::_('COM_AGENTENGINE_AGENT_DETAILS'); ?></h5>
                    </div>
                    <div class="card-body">
                        <?php echo $this->form->renderFieldset('main'); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <input type="hidden" name="task" value="" />
    <?php echo JHtml::_('form.token'); ?>
</form>
