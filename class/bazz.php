<?php
class Bazz {
	private $foobar;
	private $asd;
	public function __construct(FooBar $foobar){
		$this->foobar = $foobar;
		echo "<pre>";
	}
	public function sampleCOnfigMethod($sample_config){
		$this->asd = $sample_config;
	}
	public function getConfigurationMethods(){
		return array("sampleConfigMethod");
	}
	public function callFoobar(){
		echo "calling foobar\n";
		$this->foobar->raiseException();
		echo "called foobar\n";
	}
	public function test(){
		echo $this->asd;
	}
}
