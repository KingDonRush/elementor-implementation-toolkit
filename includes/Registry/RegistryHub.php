<?php
/**
 * Owns the versioned public extension registries.
 */

namespace EIT\Registry;

use EIT\Blueprint\FieldPrimitiveRegistry;
use EIT\Contracts\CollectionProviderInterface;
use EIT\Contracts\FormActionInterface;
use EIT\Contracts\PresentationAdapterInterface;
use EIT\Contracts\StorageAdapterInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RegistryHub {

	private $field_primitives;
	private $storage_adapters;
	private $collection_providers;
	private $form_actions;
	private $presentation_adapters;

	public function __construct() {
		$this->field_primitives = new FieldPrimitiveRegistry();
		$this->storage_adapters = new ExtensionRegistry( StorageAdapterInterface::class );
		$this->collection_providers = new ExtensionRegistry( CollectionProviderInterface::class );
		$this->form_actions = new ExtensionRegistry( FormActionInterface::class );
		$this->presentation_adapters = new ExtensionRegistry( PresentationAdapterInterface::class );
	}

	public function field_primitives() {
		return $this->field_primitives;
	}

	public function storage_adapters() {
		return $this->storage_adapters;
	}

	public function collection_providers() {
		return $this->collection_providers;
	}

	public function form_actions() {
		return $this->form_actions;
	}

	public function presentation_adapters() {
		return $this->presentation_adapters;
	}
}
