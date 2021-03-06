<?php
namespace console\helpers;
use yii\helpers\Json;
use eagle\models\SaasEbayUser;
use eagle\modules\listing\models\EbayItem;
use eagle\modules\util\helpers\UserLastActionTimeHelper;
use eagle\models\SaasEbayAutosyncstatus;
use common\api\ebayinterface\getsellerevents;
use common\api\ebayinterface\getsellerlist;
use common\api\ebayinterface\shopping\getsingleitem;
use common\api\ebayinterface\base;
use common\helpers\Helper_Array;
use eagle\modules\listing\models\EbayItemDetail;
use eagle\modules\util\helpers\ConfigHelper;

/**
 *----------------------------------------------------------------
 *<------------
 *|           |
 *|     3<----|
 *|     |     |
 *0---->----->1------>2(2:执行完FIRST,AUTO待执行)
 *     |              |
 *     <----20<------10<---
 *                   |    |
 *                  30--->
 *----------------------------------------------------------------
 *   //状态机
 *   const FIRST_PENDING=0;
 *   const FIRST_RUNNING=1;
 *   const FIRST_EXECEPT=3;
 *   const AUTO_PENDING=2;
 *   const AUTO_RUNNING=10;
 *   const AUTO_FINISH=20;
 *   const AUTO_EXECEPT=30;
 *----------------------------------------------------------------
 *  EbayAutoGetItemsController.php ---- 总crontab(第一级)
 *  EbayAutoGetItemsCsolHelper.php ---- item拉取console helper文件(第二级)
 *  EbayAutoCommonHelper.php ---- ebay console公共helper文件
 *----------------------------------------------------------------
 */
class EbayAutoGetItemsCsolHelper{
    const FIRST_PENDING=0;
    const FIRST_RUNNING=1;
    const FIRST_EXECEPT=3;
    const AUTO_PENDING=2;
    const AUTO_RUNNING=10;
    const AUTO_FINISH=20;
    const AUTO_EXECEPT=30;
    public static $cronJobId=0;
    public static $getSellerEventsCnt=0;
    private static $ebayGetItemVersion = null;
    // public static $procStatus=array(

    //     // 'Notact'=>0,//未执行
    //     // 'Active'=>1,//执行中
    //     // 'Completed'=>2,//已完成
    //     // 'PartCompleted'=>3,//第一次拉取部分完成
    //     // 'Exception'=>4,//异常情况
    //     );
    public static $delayTime=array(
        'small'=>0,
        'middle'=>3600,
        'large'=>7200,);
    public static function getCronJobId() {
        return self::$cronJobId;
    }
    public static function setCronJobId($cronJobId) {
        self::$cronJobId = $cronJobId;
    }
    public static function setAPIcount($tpyeAPI){
        switch ($tpyeAPI) {
            case 'events':
                $getSellerEventsCnt++;
                break;
            case 'list':
                # code...
                break;
            default:
                # code...
                break;
        }

    }
    public static function getAPIcount($tpyeAPI){
        $ret=0;
        switch ($tpyeAPI) {
            case 'events':
                $ret=$getSellerEventsCnt;
                break;
            case 'list':
                # code...
                break;
            default:
                # code...
                break;
        }
        return $ret;
    }
    /**
     * [checkNeedExitNot 该进程判断是否需要退出]
     * 说明:通过配置全局配置数据表ut_global_config_data的Order/dhgateGetOrderVersion 对应数值
     * @author willage 2016-10-20T13:59:00+0800
     * @update willage 2017-04-19T10:08:47+0800
     */
    public static function checkNeedExitNot(){
        $verFromConfig = ConfigHelper::getGlobalConfig("listing/ebayGetItemVersion",'NO_CACHE');
        if (empty($verFromConfig))  {
            //数据表没有定义该字段，不退出。
            return false;
        }
        //如果自己还没有定义，去使用global config来初始化自己
        if (self::$ebayGetItemVersion===null)   self::$ebayGetItemVersion = $verFromConfig;
        //如果自己的version不同于全局要求的version，自己退出，从新起job来使用新代码
        if (self::$ebayGetItemVersion <> $verFromConfig){
            echo "Version new $verFromConfig , this job ver ".self::$ebayGetItemVersion." exits \n";
            return true;
        }
        return false;
    }

    /**
     * [goFirstGetitems 同步并保存firstGetitems]
     * @author willage 2017-04-19T15:48:33+0800
     * @update willage 2017-04-19T15:48:33+0800
     */
    public static function goFirstGetitems($record,$eu,$activeUsers){
        $bgJobId=self::getCronJobId();
        $resultof=true;
        
        echo "uid ".$eu->uid."\n";
        /**
         * [拉取list,遍历拉取item]
         */
            //after time,创建时间前20days
        $atime=empty($record->last_first_finish_time)?($record->created-20*24*3600):$record->last_first_finish_time;
            //before time,创建时间后50days
        $btime=($record->created+50*24*3600);
        $grainSize=10*24*3600;//每10days拉取
        //初始时间设置
        $startTime=$atime;
        $endTime=(($startTime+$grainSize))>$btime?$btime:($startTime+$grainSize);
        $bigCustom=false;
        while($endTime<=$btime){//分时间段拉取
            echo "s=".$startTime." e=".$endTime."\n";
            $currentPage = 0;
            do {//分页拉取
                $currentPage ++;
                $pagination = array (
                    'EntriesPerPage' => 50,
                    'PageNumber' => $currentPage
                );
                //拉取list并保存
                list($result,$errCode,$mesg,$pageResultArr,$itemArr)=self::getSellerList($eu->listing_token,$eu->listing_devAccountID,$pagination,$startTime,$endTime,$eu);
                if($result=="Failure"){
                    echo __FUNCTION__." $bgJobId $record->selleruserid getSellerList fail break\n";
                    $resultof=false;
                    break;
                }
            } while (((empty($pageResultArr)&&$currentPage!=0)||$pageResultArr['TotalNumberOfPages']>$currentPage));
            echo __FUNCTION__." $bgJobId currentPage=$currentPage\n";

            if ($resultof==false) {
                break;
            }
            $startTime=$endTime;
            $endTime+=$grainSize;
            //识别大卖家
            if ($currentPage*$pagination['EntriesPerPage']>1000) {
                $bigCustom=true;
                break;
            }
        }
        /**
         * [保存记录,1记录对应1店铺]
         */
            //firstGetitem不区分活跃用户
        if ($bigCustom==true) {//大卖家推迟1小时再执行
            $record->execution_interval=1*3600;
            $record->next_execute_time=time()+$record->execution_interval;
        }else{//小卖家5分钟后再执行
            $record->execution_interval=5*60;
            $record->next_execute_time=time()+$record->execution_interval;
        }
        $record->last_first_finish_time = $startTime;//记录最后一次执行时间
        if ($resultof) {
            if ($record->created+50*24*3600==$record->last_first_finish_time) {//拉取够-20days和+50days
                $record->status_process = self::AUTO_PENDING;//完成 2
            }else{
                $record->status_process = self::FIRST_PENDING;//部分完成 0
            }
        }else{
            $record->status_process =self::FIRST_EXECEPT;//异常 3
        }
        $record->save (false);
        return $resultof;
    }

    /**
     * [goAutoGetitems 同步并保存autoGetitems]
     * @author willage 2017-04-19T15:20:53+0800
     * @update willage 2017-04-19T15:20:53+0800
     */
    public static function goAutoGetitems($record,$eu,$activeUsers){
        $bgJobId=self::getCronJobId();
        $resultof=true;
         
        echo "uid ".$eu->uid."\n";
        /**
         * [设置参数]
         */
        $nowTime=(time()-2*60);//提前2min作缓存
        $grainSize=8*3600;//时间粒度8小时
        $atime=empty($record->lastprocessedtime)?$record->created:$record->lastprocessedtime;//after time
        $btime=(($atime+48*3600)>$nowTime)?$nowTime:($atime+48*3600);//before time
        echo __FUNCTION__." $bgJobId nowtime=$nowTime\n";

        $startTime=$atime;//初始时间设置
        $endTime=(($startTime+$grainSize))>$btime?$btime:($startTime+$grainSize);
        /**
         * [分段同步并保存]
         */
        while(1){//滑窗拉取，48小时内按粒度区间拉取
            //No.2.1-get item list
            $endTime=$endTime>=$btime?$btime:$endTime;
            if ($endTime>=$btime) {
                $endTime=$btime;
                $lastSeg=true;
            }else{
                $endTime=$endTime;
                $lastSeg=false;
            }
            echo "s:".$startTime." e:".$endTime."\n";
            //modify 包括新上线、已下线和修改
            list($result,$errCode,$mesg,$itemArr)=self::_getSellerEvents($eu->listing_token,$eu->listing_devAccountID,'modifyType',$startTime,$endTime);
            if($result=="Failure"){
                echo __FUNCTION__." $bgJobId $record->selleruserid getSellerEvents errCode=$errCode, $mesg\n";
                $resultof=false;
                break;
            }
            //No.2.2-save item detail
            $_sitemap = Helper_Array::toHashmap($itemArr, 'ItemID','Site');
            foreach ($itemArr as $key => $itemVal) {
                //print_r($itemVal,false);
                $saveResult=self::_getandSaveItem($itemVal,$eu,$record,$_sitemap);
                if ($saveResult==false) {
                    echo __FUNCTION__." $bgJobId $record->selleruserid getandSaveItem fail break\n";
                    $resultof=false;
                    break;
                }
            }
            $startTime=$endTime;
            $endTime+=$grainSize;
            if ($lastSeg) {
                break;
            }
        }//end while

        /**
         * [结果处理]
         */
        if (in_array($record->ebay_uid,$activeUsers)) {
        //活跃用户12小时后自动拉取(20170124修改成12小时，以前是6小时)
            $record->execution_interval=12*3600;
            $record->next_execute_time=time()+$record->execution_interval;
        }else{//非活跃用户14天后的0点到6点之间拉取(20170124修改成14天，以前是7天)
            $record->execution_interval=14*24*3600;
            $record->next_execute_time=floor(time()/3600)*3600+$record->execution_interval+rand(0,6*3600);
        }
        $record->lastprocessedtime = $startTime;//记录最后一次执行时间
        if ($resultof) {
            $record->status_process = self::AUTO_FINISH;//成功20
        }else{
            $record->status_process = self::AUTO_EXECEPT;//失败30
        }
        $record->save (false);
        return $resultof;

    }
    /**
     * [_getandSaveItem description]
     * @author willage 2017-05-05T15:34:26+0800
     * @update willage 2017-05-05T15:34:26+0800
     */
    public static function _getandSaveItem($row,$eu,$HQ,$_sitemap){
        $_itemid = $row ['ItemID'];
        $_sku = isset($row ['SKU'])?$row ['SKU']:'';
        $_price = isset($row ['SellingStatus'] ['CurrentPrice'])?$row ['SellingStatus'] ['CurrentPrice']:'';
        $_quantity = isset($row ['Quantity'])?$row ['Quantity']:'';
        $_site = isset($row['Site'])?$row['Site']:'';
        $ei = EbayItem::find()->where ( ['itemid'=>$_itemid] )->one ();
        $getitem_api = new getsingleitem();
        try {
            $_r = $getitem_api->apiItem($_itemid);
        } catch ( \Exception $ex ) {
            \Yii::error(print_r($ex->getMessage()));
        }
        // print_r($_r,false);
        // \Yii::info( print_r($_r,false) ,"file");
        if (!$getitem_api->responseIsFail) {//success 保存 同步状态
            \Yii::info( 'get success' ,"file");
            echo "-get success ItemID ".$_itemid."\n";
            $getitem_api->save ( $_r,$HQ, $_sitemap);
            return true;
        } else {//fail 保存同步状态
            \Yii::info( 'get failed' );
            echo "==get failed ItemID ".$_itemid."\n";
            $errCode=isset($r['Errors'] ['ErrorCode'])?NULL:$r['Errors'] ['ErrorCode'];
            $mesg=NULL;
            if (isset($r['Errors']['ShortMessage'])) {
                $mesg="[ShortMessage]:".$r['Errors']['ShortMessage']." [LongMessage]:".$r['Errors']['LongMessage'];
            }
            echo "$errCode $mesg\n";
            $ei->setAttributes ( array (
                'uid' => $eu->uid,
                'selleruserid' => $eu->selleruserid,
                'itemid' => $_itemid,
                'site'=>$_site,
                'quantity' => $_quantity,
                'listingstatus' => @$row ['SellingStatus'] ['ListingStatus'],
                'sku' => $_sku,
                'startprice' => @$row ['StartPrice'],
                'buyitnowprice' => @$row ['BuyItNowPrice'],
                'endtime' => strtotime ( $row ['ListingDetails'] ['EndTime'] )
            ) );
            $detail = EbayItemDetail::find()->where(['itemid'=>$_itemid])->one();
            if (empty($detail)){
                $detail = new EbayItemDetail();
            }
            $detail->itemid=$_itemid;
            // 保存location
            if (! empty ( $row ['Location'] )) {
                if (is_array ( $row ['Location'] )) {
                    $detail->location = reset ( $row ['Location'] );
                }
            }
            $detail->storecategoryid = @$row ['Storefront'] ['StoreCategoryID'];
            $detail->storecategory2id = @$row ['Storefront'] ['StoreCategory2ID'];
            $detail->sellingstatus = $row ['SellingStatus'];
            if ($_price) {
                $ei->currentprice = $_price;
            }
            /**
             * sku 不是最新的
             */
            // 保存多属性库存状态
            if (isset ( $row ['Variations'] )) {
                $t_variations = $detail->variation;
                if ($row ['Variations'] ['Variation']) {
                    $t_variations ['Variation'] = $row ['Variations'] ['Variation'];
                }
                if (isset ( $row ['Variations'] ['Pictures'] )) {
                    $t_variations ['Pictures'] = $row ['Variations'] ['Pictures'];
                }
                if (isset ( $row ['Variations'] ['VariationSpecificsSet'] )) {
                    $t_variations ['VariationSpecificsSet'] = $row ['Variations'] ['VariationSpecificsSet'];
                }
                $detail->variation = $t_variations;
            }
            $ei->save (false);
            $detail->save(false);
            return false;
        }
    }

    /**
     * [getSellerEvents description]
     * @Author   willage
     * @DateTime 2016-10-14T10:24:03+0800
     * @return   [type]                   [description]
     */
    public static function _getSellerEvents($token,$DevAcccountID,$typeof,$start,$end){
        $api = new getsellerevents();
        $api->resetConfig($DevAcccountID);
        //参数设置
        $api->eBayAuthToken = $token;
        // $api->_before_request_xmlarray ['OutputSelector'] = array (
        //  // 'PaginationResult',
        //  'ItemArray.Item.Quantity',
        //  'ItemArray.Item.Variations.Variation',
        //  'ItemArray.Item.SKU',
        //  'ItemArray.Item.SellingStatus',
        //  'ItemArray.Item.ItemID',
        //  'ItemArray.Item.Site',
        //  'ItemArray.Item.StartPrice',
        //  'ItemArray.Item.Storefront.StoreCategoryID',
        //  'ItemArray.Item.Storefront.StoreCategory2ID',
        //  'ItemArray.Item.Location',
        //  'ItemArray.Item.ListingDetails.EndTime'
        // );
        //$currentPage = 0;
        //$activeItemID = array ();
        switch ($typeof) {
            case 'startType':
            $params=array (
            'StartTimeFrom' => base::dateTime($start),
            'StartTimeTo' => base::dateTime($end),//$end//
            );
                break;
            case 'modifyType':
            $params=array (
            'ModTimeFrom' => base::dateTime($start),
            'ModTimeTo' => base::dateTime($end),//$end//
            );
                break;
            case 'endType':
            $params=array (
            'EndTimeFrom' => base::dateTime($start),
            'EndTimeTo' => base::dateTime($end),//$end//
            );
                break;
            default:
            $params=array (
            'ModTimeFrom' => base::dateTime($start),
            'ModTimeTo' => base::dateTime($end),//$end//
            );
                break;
        }

        //调用API
        try {
            $r = $api->api ($params);
        } catch ( Exception $ex ) {
            \Yii::info ( print_r ( $pagination, true ) . print_r ( $r, true ) ,"file");
            continue;
        }
        //print_r($r,false);
        //响应处理
        if ($api->responseIsFailure ()) {
            $result=$r['Ack'];
            list($errCode,$mesg)=self::_dealwithErrResult($r);
            $itemArr=array();
        }else{
            $result=$r['Ack'];//可能值CustomCode(内部或未来使用)、Failure、Success、Warning
            $errCode='0';
            $mesg=NULL;
            $itemArr=isset($r['ItemArray']['Item'])?$r['ItemArray']['Item']:array();
            if (isset ( $itemArr ['ItemID'] )) {
                $itemArr = array (
                    $itemArr
                );
            }
        }
        return array($result,$errCode,$mesg,$itemArr);
    }

    public static function _dealwithErrResult($r){
        if ($r ['Errors'] ['ErrorCode'] == 932) {
            \Yii::info ( 'Auth token is hard expired',"file" );
            // echo "selleruserid=".$HQ->selleruserid.", Auth token is hard expired\n";

        }
        if ($r ['Errors'] ['ErrorCode'] == 340) {
            \Yii::info ( 'Page number is out of range',"file" );
            echo 'Page number is out of range\n';
        }
        $errCode=$r['Errors'] ['ErrorCode'];
        $mesg="[ShortMessage]:".$r['Errors']['ShortMessage']." [LongMessage]:".$r['Errors']['LongMessage'];
        return array($errCode,$mesg);
        // 522, //No time window specified
        // 12325, //We cannot retrieve the user information at this time, please try it later
        // 18000, //Daily limit exceeded,超出每日请求限制
        // 10008, //<replaceable_value> value "replaceable_value" is out of range
        // 521, //The specified time window is invalid
    }

    /**
     * [getSellerList description]
     * @Author   willage
     * @DateTime 2016-10-14T10:24:11+0800
     * @return   [type]                   [description]
     */
    public static function getSellerList($token,$DevAcccountID,$pagination,$startTime,$endTime,$eu){
        //参数设置
        $api = new getsellerlist();
        $api->resetConfig($DevAcccountID);
        $api->eBayAuthToken = $token;
        $_start = base::dateTime($startTime);
        $_end = base::dateTime ($endTime);
        // $api->_before_request_xmlarray ['OutputSelector'] = array (
        //  'PaginationResult',
        //  'ItemArray.Item.Quantity',
        //  'ItemArray.Item.Variations.Variation',
        //  'ItemArray.Item.SKU',
        //  'ItemArray.Item.SellingStatus',
        //  'ItemArray.Item.ItemID',
        //  'ItemArray.Item.Site',
        //  'ItemArray.Item.StartPrice',
        //  'ItemArray.Item.Storefront.StoreCategoryID',
        //  'ItemArray.Item.Storefront.StoreCategory2ID',
        //  'ItemArray.Item.Location',
        //  'ItemArray.Item.ListingDetails.EndTime'
        // );
        //$currentPage = 0;
        //$activeItemID = array ();
        //调用API
        try {
            $r = $api->api($pagination,'ReturnAll',$_start,$_end);
        } catch (Exception $ex) {
            \Yii::info ( print_r ( $pagination, true ) . print_r ( $r, true ) ,"file");
        }
        // print_r($r,false);
        // \Yii::info ( print_r($r,true) ,"file");
        //响应处理
        if ($api->responseIsFailure ()) {
            $result=$r['Ack'];
            list($errCode,$mesg)=self::_dealwithErrResult($r);
            $itemArr=array();
            $pageResultArr=array();
        }else{
            $result=$r['Ack'];//可能值CustomCode(内部或未来使用)、Failure、Success、Warning
            $errCode='0';
            $mesg=NULL;
            $itemArr=isset($r['ItemArray']['Item'])?$r['ItemArray']['Item']:array();
            if (isset ( $itemArr ['ItemID'] )) {
                $itemArr = array (
                    $itemArr
                );
            }
            $pageResultArr=$r['PaginationResult'];
            $_sitemap = Helper_Array::toHashmap($itemArr, 'ItemID','Site');
            foreach ($itemArr as $key => $itemVal) {
                $api->save($itemVal,$eu,$_sitemap);
            }
        }
        return array($result,$errCode,$mesg,$pageResultArr,$itemArr);
    }


    /**
     * [bgGetitem description]
     * @Author   willage
     * @DateTime 2016-10-21T10:07:07+0800
     * @param    [type]                   $ebayUid   [ebay uid]
     * @param    [type]                   $startTime [当前时间的前N天]
     * @param    [type]                   $endTime   [当前时间的后N天]
     * @return   [type]                              [description]
     */
    public static function bgGetitem($ebayUid,$sDayTime,$eDayTime){
        $resultof=true;
        //No.1-get record
        $HQ = SaasEbayAutosyncstatus::find()
        ->where(['ebay_uid'=>$ebayUid])
        ->andWhere(['type'=>7])
        ->andWhere(['status'=>1])
        ->andWhere("status_process NOT IN (1,10)")
        ->one();
        if (count ( $HQ ) == 0) {
            return false;
        }
        //No.2-get item

        //抢记录
        $HQ=self::_lockEbayAutosyncRecord($HQ->id);
        if ($HQ==NULL) return false;
        echo $HQ->selleruserid." ".$HQ->ebay_uid."\n";
        //提取用户，授权过时，不拉取
        $eu = SaasEbayUser::find()->where ( ['selleruserid'=>$HQ->selleruserid] )->andWhere('expiration_time>='.time())->one();
        if (empty($eu)){
            echo __FUNCTION__." ".$HQ->selleruserid." cant be found"."or token timeout expiration_time\n";
        }
         
        echo "uid ".$eu->uid."\n";
        //No.2-拉取list，遍历拉取item
            //after time,创建时间前Ndays
        $atime=time()-$sDayTime*24*3600;
            //before time,创建时间后Ndays
        $btime=time()+$eDayTime*24*3600;
        $grainSize=10*24*3600;//每10days拉取
        //初始时间设置
        $startTime=$atime;
        $endTime=$startTime+$grainSize;
        while($endTime<=$btime){//分时间段拉取
            echo "s=".$startTime." e=".$endTime."\n";
            $currentPage = 0;
            do {//分页拉取
                $currentPage ++;
                $pagination = array (
                    'EntriesPerPage' => 50,
                    'PageNumber' => $currentPage
                );
                //拉取list并保存
                list($result,$errCode,$mesg,$pageResultArr,$itemArr)=self::getSellerList($eu->token,$eu->DevAcccountID,$pagination,$startTime,$endTime,$eu);
                if($result=="Failure"){
                    echo __FUNCTION__." $HQ->selleruserid getSellerList fail break\n";
                    $resultof=false;
                    break;
                }
            } while (((empty($pageResultArr)&&$currentPage!=0)||$pageResultArr['TotalNumberOfPages']>$currentPage));
            echo __FUNCTION__." currentPage=$currentPage\n";

            if ($resultof==false) {
                break;
            }
            $startTime=$endTime;
            $endTime+=$grainSize;
        }
        //No.3-save record
        $HQ->last_first_finish_time = $startTime;//记录最后一次执行时间
        if ($resultof) {
            $HQ->status_process = self::AUTO_PENDING;//完成2
        }else{
            $HQ->status_process = self::FIRST_PENDING;//部分完成3
        }
        $HQ->save (false);

        return true;
    }

}//end class
