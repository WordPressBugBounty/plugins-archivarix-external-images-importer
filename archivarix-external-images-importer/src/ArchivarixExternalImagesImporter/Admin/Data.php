<?php


namespace ArchivarixExternalImagesImporter\Admin;

class Data
{

  private $state;
  private $field;
  private $data;

  public function __construct( $state )
  {
    $this->state = $state;
    $this->field = "{$this->state->baseName}_settings";
    $this->data  = get_option( $this->field );
  }

  public function getOption( $name, $default = false )
  {
    if ( isset( $this->data[$name] ) && !empty( $this->data[$name] ) ) {
      return $this->data[$name];
    }

    return $default;
  }

  public function updateOption( $name, $value )
  {
    $this->data[$name] = $value;

    return update_option( $this->field, $this->data );
  }

}
