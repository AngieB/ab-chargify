<?php

class ChargifyPaymentProfile extends ChargifyConnector
{
  public $id;
  
  public function __construct(SimpleXMLElement $payment_xml_node)
  {
    //Load object dynamically and convert SimpleXMLElements into strings
    foreach($payment_xml_node as $key => $element) { $this->$key = (string)$element; }
  }

  /* Getters */

  public function getPaymentProfileID() { return $this->id; }

}
