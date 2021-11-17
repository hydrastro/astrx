<?php
class FooBar {
	public $errors = array();
	public $exceptions = array();
	public function raiseException(){
		$this->errors[] = "foobar error test";
		$this->exceptions[] = new Exception("foobar error test");

		echo "I have set exceptions on me (foo bar)";



	}
}
