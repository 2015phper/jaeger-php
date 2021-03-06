<?php

namespace JaegerPhp;

use JaegerPhp\Sampler\Sampler;
use OpenTracing\SpanReference;
use OpenTracing\SpanContext;
use OpenTracing\Propagators\Writer;
use OpenTracing\Propagators\Reader;
use OpenTracing\Propagator;
use OpenTracing\Tracer;
use JaegerPhp\Reporter\Reporter;

class Jaeger implements Tracer{

    private $reporter = null;

    private $sampler = null;

    private $gen128bit = false;

    public static $handleProto = null;

    public $spans = [];

    public $tags = [];

    public $process = null;

    public $procesSize = 0;

    public $serverName = '';

    public $bufferSize = '';

    public $processThrift = '';

    public $spanThrifts = [];

    public function __construct($serverName = '', Reporter $reporter, Sampler $sampler){

        $this->reporter = $reporter;

        $this->sampler = $sampler;
        $this->setTags($this->sampler->getTags());
        $this->setTags($this->getEnvTags());

        if($serverName == '') {
            $this->serverName = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'unknow server';
        }else{
            $this->serverName = $serverName;
        }
    }


    /**
     * @param array $tags  key => value
     */
    public function setTags(array $tags = []){
        if(!empty($tags)) {
            $this->tags = array_merge($this->tags, $tags);
        }
    }


    /**
     * init span info
     * @param string $operationName
     * @param SpanReference|null $parentReference
     * @param null $startTimestamp
     * @param array $tags
     * @return JSpan
     */
    public function startSpan($operationName, SpanReference $parentReference = null
        , $startTimestamp = null, array $tags = []
    ){
        $parentSpan = null;
        $spanContext = $parentReference->getContext();
        if($spanContext->traceId){
            $parentSpan = $spanContext;
        }

        if(!$parentSpan){
            $low = Helper::identifier();
            if($this->gen128bit == true){
                $high = Helper::identifier();
                $traceId = Helper::toHex($low, $high);
            }else{
                $traceId = Helper::toHex($low);
            }
            $spanId = Helper::toHex($low);

            $flags = $this->sampler->IsSampled();
            $newSpan = new JSpanContext($traceId, $spanId, 0, $flags, null, 0);
        }else{
            $newSpan = new JSpanContext($parentSpan->traceId, Helper::toHex(Helper::identifier())
                , $parentSpan->spanId, $parentSpan->flags, null, 0);
        }

        $span = new JSpan($operationName, $newSpan);
        if(!empty($tags)){
            $span->addTags($tags);
        }

        if($newSpan->isSampled() == 1) {
            $this->spans[] = $span;
        }

        return $span;
    }


    public function startSpanWithOptions($operationName, $options){

    }


    /**
     * 注入
     * @param SpanContext $spanContext
     * @param int|string $format
     * @param Writer $carrier
     */
    public function inject(SpanContext $spanContext, $format, Writer $carrier){
        if($format == Propagator::TEXT_MAP){
            $carrier->set(Helper::TracerStateHeaderName, $spanContext->buildString());
        }else{
            throw new Exception("not support format");
        }
    }


    /**
     * 提取
     * @param int|string $format
     * @param Reader $carrier
     */
    public function extract($format, Reader $carrier){
        if($format == Propagator::TEXT_MAP){
            $carrierInfo = $carrier->getIterator();
            if(isset($carrierInfo[Helper::TracerStateHeaderName]) && $carrierInfo[Helper::TracerStateHeaderName]){
                list($traceId, $spanId, $parentId,$flags) = explode(':', $carrierInfo[Helper::TracerStateHeaderName]);
                return new JSpanContext($traceId, $spanId, $parentId, $flags, null, 0);
            }

            return new JSpanContext(0, 0, 0, 0, null, 0);
        }else{
            throw new Exception("not support format");
        }
    }


    public function getSpans(){
        return $this->spans;
    }


    public function reportSpan(){
        if(count($this->spans) > 0) {
            $this->reporter->report($this);
        }
    }


    public function getEnvTags(){
        $tags = [];
        if(isset($_SERVER['JAEGER_TAGS']) && $_SERVER['JAEGER_TAGS'] != ''){
            $envTags = explode(',', $_SERVER['JAEGER_TAGS']);
            foreach ($envTags as $envK => $envTag){
                list($key, $value) = explode('=', $envTag);
                $tags[$key] = $value;
            }
        }

        return $tags;
    }


    public function gen128bit(){
        $this->gen128bit = true;
    }


    /**
     * 结束,发送信息到jaeger
     */
    public function flush(){
        $this->reportSpan();
        $this->reporter->close();
        Config::getInstance()->destroyTrace($this->serverName);
    }

}


?>