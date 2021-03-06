<?php

use eagle\components\DateSelectWidget;

$this->registerCssFile(\Yii::getAlias('@web').'/js/project/report/jquery-ui-1.10.4.custom.min.css' , ['depends' => ['yii\jui\JuiAsset','yii\bootstrap\BootstrapPluginAsset']]);
// $this->registerJsFile(\Yii::getAlias('@web')."/js/project/report/jquery-ui-1.10.4.custom.min.js", ['depends' => ['yii\jui\JuiAsset','yii\bootstrap\BootstrapPluginAsset']]);
$this->registerJsFile(\Yii::getAlias('@web')."/js/project/report/report.js", ['depends' => ['yii\jui\JuiAsset','yii\bootstrap\BootstrapPluginAsset']]);

$this->registerJsFile(\Yii::getAlias('@web')."/js/project/report/product/worth.js", ['depends' => ['yii\web\JqueryAsset']]);
$this->registerJs("worth.init();" , \yii\web\View::POS_READY);

?>

<style>
.modal-dialog{
		width: 1000px;
}
</style>

<table>
<tr>
	<td>
	请选择销售店
		<select id="report-product-worth-select">
			<option selected="selected" value="0-0">(全部)</option>
			<?php 
			if (count($shopArr) > 0){
				foreach ($shopArr as $shop){
			?>
			<option value='<?=$shop['sourceAndId'] ?>'><?=$shop['sourceAndId'] ?></option>
			<?php
				}
			}
			?>
		</select>
	</td>
	<td style="width: 600px;">
		<span style="margin-left: 10px;">
			<?php echo DateSelectWidget::widget(); ?>
		</span>
	</td>
	<td><button class="btn btn-info" style="margin-bottom: 10px;" onclick="worth.exportExcel()">导出Excel</button></td>
</tr>
</table>

<div>
<table cellspacing="0" cellpadding="0" width="100%" class="table table-hover" id="worth-list-table">
<tr>
<th>排名</th><th>SKU</th><th>商品名称</th><th>销售商品数量</th><th>销售总金额</th><th>订单数量(销售次数)</th>
</tr>
<?php
	$index = $worthDataArr['pagination']->offset + 1;

	if (count($worthDataArr['data']) > 0){
		foreach ($worthDataArr['data'] as $worthSales){
?>
<tr>
	<td><?=$index; ?></td><td><?=$worthSales['sku'] ?></td><td><?=$worthSales['product_name'] ?></td>
	<td><?=$worthSales['sale'] ?></td><td><?=$worthSales['price'] ?></td><td><?=$worthSales['ordered'] ?></td>
</tr>
<?php 
			$index++;
		}
	}
?>

</table>
</div>

<?php if($worthDataArr['pagination']):?>
<div>
	<div id="worth-list-pager" class="pager-group">
	    <?= \eagle\widgets\SizePager::widget(['isAjax'=>true , 'pagination'=>$worthDataArr['pagination'] , 'pageSizeOptions'=>array( 5 , 20 , 50 , 100 , 200 ) , 'class'=>'btn-group dropup']);?>
	    <div class="btn-group" style="width: 49.6%;text-align: right;">
	    	<?=\eagle\widgets\ELinkPager::widget(['isAjax'=>true , 'pagination' => $worthDataArr['pagination'],'options'=>['class'=>'pagination']]);?>
		</div>
	</div>
</div>
<?php endif;?>

<?php 
// 该例子支持通过 ajax 更新table 和 pagination页面，启动该功能需要下方代码初始化js配置，以及给下面两个widget配置"isAjax"=>true,
$options = array();
$options['pagerId'] = 'worth-list-pager';// 下方包裹 分页widget的id
$options['action'] = \Yii::$app->request->getPathInfo(); // ajax请求的 action
$options['page'] = $worthDataArr['pagination']->getPage();// 当前页码
$options['per-page'] = $worthDataArr['pagination']->getPageSize();// 当前page size
$options['sort'] = isset($_REQUEST['sort'])?$_REQUEST['sort']:'';// 当前排序
$this->registerJs('$("#worth-list-table").initGetPageEvent('.json_encode($options).')' , \Yii\web\View::POS_READY);
?>