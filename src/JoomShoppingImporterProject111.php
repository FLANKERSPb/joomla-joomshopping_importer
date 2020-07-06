<?php

// TODO: Refactor

defined('FL_JSHOP_IMPORTER') or die();

class JoomShoppingImporterProject111 extends JoomShoppingImporter
{
	const NAME = 'Project111';

	const DEFAULTS = [
		'vendor_id' => null, // ID поставщика в базе
		'login'     => null, // login к gifts.ru
		'password'  => null, // password к gifts.ru
	];

	// Задержка между загрузкой картинок (есть ограничение со стороны gifts)
	const IMPORT_TIMEOUT = 0.21;

	// Коды в файле product.xml Новика и закрыт
	const PRODUCT_NEW_STATUS = '0';
	const PRODUCT_CLOSE_STATUS = '3';

	// Код фильтра производителей в файле filters.xml
	const FILTER_MANUFACTURERS = 13;

	// Обрезка названий атрибутов
	const TRIM_ATTR_SIZE = array(
		' v2',
	);

	static $params;
	static $src_path;


	public static function getCategories($xml = null)
	{
		static $json = [];

		static $level = 0;
		static $result = [];

		if (!$xml)
		{
			$xml = self::loadXML('treeWithoutProducts');

			if (!$xml)
			{
				return [];
			}

			if (file_exists(self::CFG_CATEGORIES))
			{
				$json = json_decode(file_get_contents(self::CFG_CATEGORIES), true)[self::$params['vendor_id']];
			}
		}

		foreach ($xml->children() as $name => $item)
		{
			if ($name == 'page')
			{
				$id = (string) $item->page_id;

				if ($id != 1)
				{
					$parent_id = (string) $item->attributes()['parent_page_id'];

					$result[$id] = [
						'id'        => $id,
						'parent_id' => $parent_id == '1' ? 0 : $parent_id,
						'title'     => (string) $item->name,
						'uri'       => (string) $item->uri,
						'level'     => $level,
						'is_new'    => '1',
						'new_title' => '',
						'action'    => '',
						'value'     => '',
					];

					if (is_array($json) && array_key_exists($id, $json))
					{
						$result[$id]['is_new']    = '0';
						$result[$id]['new_title'] = $json[$id]['new_title'];
						$result[$id]['action']    = $json[$id]['action'];
						$result[$id]['value']     = $json[$id]['value'];
					}
				}
			}

			$level += 1;
			self::getCategories($item);
			$level -= 1;
		}

		return $result;
	}


	function updateProducts()
	{
		if ($this->updateCategories())
		{
			// Import filters
			$filters = $this->loadXML('filters');

			if ($filters)
			{
				$this->importFilters($filters);
				unset($filters);

				// Import products
				$products = $this->loadXML('product');

				if ($products)
				{
					$this->importSizes($products);
					$this->importPrints($products);
					$this->importProducts($products);

					$this->clearProducts();

					unset($products);
				}
			}
		}
	}


	function updateStock()
	{
		$stock = $this->loadXML('stock');

		if ($stock)
		{
			$this->importStock($stock);

			return true;
		}

		return false;
	}


	protected function importProducts($xml)
	{
		self::logMethodStart(__FUNCTION__);

		$this->setState(0, 'products', 'vendor_id=' . self::$params['vendor_id']);

		$i = 0;

		foreach ($xml->children() as $name => $item)
		{
			$i++;

			if ($name == 'product')
			{
				if (!self::$params['full_import'] && ($i % 250) != 0)
				{
					continue;
				}

				$this->importProduct($item);
			}
		}

		$this->report();

		self::logMethodComplete(__FUNCTION__);
	}


	protected function importProduct($xml)
	{
		switch ((int) $xml->status->attributes()['id'])
		{
			case 0:
				$label = self::$params['product_label_new'];
				break;
			case 3:
				return;
				break;
			default:
				$label = 0;
		}

		static $init = false;
		static $model;
		static $products;
		static $categories;
		static $prints;
		static $sizes;
		static $manufacturers;
		static $tree;

		if (!$init)
		{
			$model = $this->getModel('products');

			$products      = $this->getList('products', 'product_ean', 'product_id');
			$categories    = $this->getList('categories', 'fl_code', 'category_id');
			$prints        = $this->getList('productFieldValues', 'fl_code', 'id', ['field_id=' . self::$params['fields']['print'], 'fl_source=' . self::$params['vendor_id']]);
			$sizes         = $this->getList('attrValues', 'value_id', 'name_ru-RU', ['attr_id=' . self::$params['attribs']['size']]);
			$manufacturers = $this->getList('manufacturers', 'name_ru-RU', 'manufacturer_id');
			$tree          = $this->loadXML('tree');

			$init = true;
		}

		$name  = trim((string) $xml->name);
		$brand = $manufacturers[trim((string) $xml->brand)];
		$code  = self::$params['vendor_id'] . '_' . (string) $xml->product_id;

		$item = array(
			'parent_id' => 0,

			'product_ean'       => $code,
			'manufacturer_code' => (string) $xml->code,

			'product_quantity'     => 0,
			'product_availability' => 0,

			'date_modify' => JFactory::getDate('now', self::$timeZone)->toSql(true),

			'product_publish' => 1,
			'product_tax_id'  => self::$params['tax_id'],
			'currency_id'     => self::$params['currency_id'],

			'product_weight' => (string) $xml->weight,

			'product_manufacturer_id' => $brand ? $brand : '0',

			'label_id' => $label,

			'vendor_id' => self::$params['vendor_id'],

			'name_ru-RU'        => $name,
			'alias_ru-RU'       => JFilterOutput::stringURLSafe($name, 'ru-RU'),
			'description_ru-RU' => self::prepareContent((string) $xml->content),
		);

		$item_categories = [];
		$item_cats       = $tree->xpath(".//product/product[.='{$xml->product_id}']/parent::*/page");

		if ($item_cats)
		{
			foreach ($item_cats as $cat)
			{
				$category = $this->getProductCategory((string) $cat, self::$params['vendor_id']);

				if ($category)
				{
					$item_categories[] = $category['category_id'];
				}
			}

			if (count($item_categories))
			{
				$item['category_id'] = $item_categories;
			}
			else
			{
				$this->report[3]['Products category not found']            += 1;
				$this->report[3]['Category not found for product' . $name] = (string) $xml->code;

				return;
			}
		}
		else
		{
			$this->report[3]['Products without categories']        += 1;
			$this->report[3]['Product without category: ' . $name] = (string) $xml->code;

			return;
		}

		if ($products[$code])
		{
			$item['product_id'] = $products[$code];

			$this->report[5]['Products updated'] += 1;
		}
		else
		{
			$item['product_old_price'] = '0';
			$item['product_buy_price'] = '0';

			$this->report[5]['Products imported'] += 1;
		}

		if ($xml->group)
		{
			$item['extra_field_' . self::$params['fields']['group']] = (string) $xml->group;
		}

		if ($xml->matherial)
		{
			$item['extra_field_' . self::$params['fields']['matherial']] = (string) $xml->matherial;
		}

		if ($xml->product_size)
		{
			$item['extra_field_' . self::$params['fields']['size']] = (string) $xml->product_size;
		}

		if ($xml->pack)
		{
			$item['extra_field_' . self::$params['fields']['amount']] = (string) $xml->pack->amount;
			$item['extra_field_' . self::$params['fields']['weight']] = (string) $xml->pack->weight;
			$item['extra_field_' . self::$params['fields']['volume']] = (string) $xml->pack->volume;
			$item['extra_field_' . self::$params['fields']['sizex']]  = (string) $xml->pack->sizex;
			$item['extra_field_' . self::$params['fields']['sizey']]  = (string) $xml->pack->sizey;
			$item['extra_field_' . self::$params['fields']['sizez']]  = (string) $xml->pack->sizez;
		}

		$price = $xml->xpath("price/name[.='End-User']/parent::*/value");

		if ($price)
		{
			$item['product_price'] = (string) $price[0];
		}

		$fields = [];

		if ($xml->print && self::$params['fields']['print'])
		{
			foreach ($xml->print as $print)
			{
				$print_code = self::$params['vendor_id'] . '_' . self::$params['fields']['print'] . '-' . (string) $print->name;

				if ($prints[$print_code])
				{
					$fields['extra_field_' . self::$params['fields']['print']][] = $prints[$print_code];
				}
			}

			$item['attrib_ind_id']        = [self::$params['attribs']['print'], self::$params['attribs']['print']];
			$item['attrib_ind_value_id']  = explode(',', self::$params['attribs_defaults']['print']);
			$item['attrib_ind_price_mod'] = ['+', '+'];
			$item['attrib_ind_price']     = ['0', '0'];

		}

		if ($xml->filters)
		{
			foreach ($xml->filters->filter as $filter)
			{
				if ((int) $filter->filtertypeid != self::FILTER_MANUFACTURERS)
				{
					$field_code = self::$params['vendor_id'] . '_' . (string) $filter->filtertypeid;
					$field      = $this->getItem($field_code, 'productFields');

					if ($field['id'])
					{
						$fieldValue_code = self::$params['vendor_id'] . '_' . $field['id'] . '-' . (string) $filter->filterid;
						$fieldValue      = $this->getItem($fieldValue_code, 'productFieldValues');

						if ($fieldValue['id'])
						{
							$fields['extra_field_' . $field['id']][] = $fieldValue['id'];
						}
					}
				}
			}
		}

		if (count($fields))
		{
			$item['productfields'] = $fields;
		}

		$images = [];
		$files  = [];

		if ($xml->super_big_image->attributes()['src'])
		{
			$images[] = (string) $xml->super_big_image->attributes()['src'];
		}

		if ($xml->product_attachment)
		{
			foreach ($xml->product_attachment as $attachment)
			{
				if ($attachment->meaning == 1)
				{
					$images[] = (string) $attachment->image;
				}
				else
				{
					$files[] = (string) $attachment->file;
				}
			}
		}

		$item = array_merge($item, self::setImages($images, $item['product_id'], $code, self::$src_path));

		if ($xml->product)
		{
			$attrib_id              = [];
			$attrib_price           = [];
			$attr_count             = [];
			$attr_ean               = [];
			$attr_manufacturer_code = [];
			$attr_weight            = [];
			$attrib_old_price       = [];
			$attrib_buy_price       = [];
			$product_attr_id        = [];

			if (self::$params['attribs']['size'])
			{
				$attribs = [];

				if ($item['product_id'])
				{
					$attribs = $this->getItems('productsAttr', 'attr_' . self::$params['attribs']['size'], 'product_id=' . $item['product_id']);
				}

				foreach ($xml->product as $size)
				{
					if ($size->size_code)
					{
						$size_name = trim(str_replace(self::TRIM_ATTR_SIZE, '', trim((string) $size->size_code)));

						$size_id = (string) array_search($size_name, $sizes);

						if ($size_id != false)
						{
							$attrib_id[self::$params['attribs']['size']][] = $size_id;

							$attrib_price[]           = (string) $size->price->price;
							$attr_fl_source[]         = self::$params['vendor_id'];
							$attr_ean[]               = self::$params['vendor_id'] . '_' . (string) $size->product_id;
							$attr_manufacturer_code[] = (string) $size->code;
							$attr_weight[]            = (string) $size->weight;

							if ($item['product_id'] && $attribs[$size_id])
							{
								$attr_count[]       = $attribs[$size_id]['count'];
								$attrib_old_price[] = $attribs[$size_id]['old_price'];
								$attrib_buy_price[] = $attribs[$size_id]['buy_price'];
								$product_attr_id[]  = $attribs[$size_id]['product_attr_id'];
							}
							else
							{
								$attr_count[]       = '0';
								$attrib_old_price[] = '0';
								$attrib_buy_price[] = '0';
								$product_attr_id[]  = '0';
							}
						}
					}
				}
			}

			if ($product_attr_id)
			{
				$item['attrib_id']              = $attrib_id;
				$item['attrib_price']           = $attrib_price;
				$item['attr_count']             = $attr_count;
				$item['attr_ean']               = $attr_ean;
				$item['attr_manufacturer_code'] = $attr_manufacturer_code;
				$item['attr_weight']            = $attr_weight;
				$item['attrib_old_price']       = $attrib_old_price;
				$item['attrib_buy_price']       = $attrib_buy_price;
				$item['product_attr_id']        = $product_attr_id;
			}
		}

		$model->save($item);
	}


	protected function importFilters($xml)
	{
		self::logMethodStart(__FUNCTION__);

		foreach ($xml->filtertypes[0] as $filtertype)
		{
			if ((int) $filtertype->filtertypeid == self::FILTER_MANUFACTURERS)
			{
				$this->importManufacturers($filtertype);
			}
			else
			{
				$this->importField($filtertype);
			}
		}

		$this->report();

		self::logMethodComplete(__FUNCTION__);
	}


	protected function importManufacturers($xml)
	{
		static $model;

		if (!$model)
		{
			$model = $this->getModel('manufacturers');
			$this->setState(0, 'manufacturers', 'fl_source=' . self::$params['vendor_id']);
		}

		$items = array();

		foreach ($xml->filters->filter as $filter)
		{
			$code = self::$params['vendor_id'] . '_' . $filter->filterid;
			$name = trim((string) $filter->filtername);

			$item = array(
				'fl_code'              => $code,
				'fl_source'            => self::$params['vendor_id'],
				'manufacturer_publish' => 1,
				'name_ru-RU'           => $name,
				'alias_ru-RU'          => JFilterOutput::stringURLSafe($name, 'ru-RU'),
			);

			$tmp = $this->getItem($code, 'manufacturers');

			if ($tmp)
			{
				$item['manufacturer_id'] = $tmp['manufacturer_id'];
				$item['ordering']        = $tmp['ordering'];

				$this->report[5]['Manufacturers updated'] += 1;
			}
			else
			{
				$item['ordering'] = ++self::$counter['manufacturers'];

				$this->report[5]['Manufacturers imported'] += 1;
			}

			$model->save($item);
		}
	}


	protected function importPrints($xml)
	{
		self::logMethodStart(__FUNCTION__);

		$prints = $xml->xpath('product/print');

		$result = [];

		$tmp = [];

		foreach ($prints as $key => $print)
		{
			$name = trim((string) $print->name);

			if (array_key_exists($name, $tmp))
			{
				unset($prints[$key]);
			}
			else
			{
				$tmp[$name] = '';

				$code = 'print_' . strtolower($name);

				$result[$code] = trim((string) $print->description) . ' (' . $name . ')';
			}
		}

		$this->importFieldValues($prints, self::$params['fields']['print'], 'print');

		// $result['print__none'] = '- нет -';

		// natcasesort($result);

		// $this->importAttributValues($result, self::$params['attribs']['print']);

		self::logMethodComplete(__FUNCTION__);
	}


	protected function importSizes($xml)
	{
		self::logMethodStart(__FUNCTION__);

		$sizes = $xml->xpath('product/product/size_code');

		$result = [];

		foreach ($sizes as $key => $size)
		{
			$name = trim(str_replace(self::TRIM_ATTR_SIZE, '', trim((string) $size)));

			if (!in_array($name, $result))
			{
				$code          = 'size_' . str_replace('-', '_', JFilterOutput::stringURLSafe($name, 'ru-RU'));
				$result[$code] = $name;
			}
		}

		$exists = $this->getList('attrValues', 'fl_code', 'name_ru-RU', ['attr_id=' . self::$params['attribs']['size']]);

		$result = array_diff_key($result, $exists);

		$this->importAttributValues($result, self::$params['attribs']['size']);

		self::logMethodComplete(__FUNCTION__);
	}


	protected function importField($xml)
	{
		static $model;

		if (!$model)
		{
			$model = $this->getModel('productFields');
		}

		$code = self::$params['vendor_id'] . '_' . (int) $xml->filtertypeid;

		$name = trim((string) $xml->filtertypename);

		$item = array(
			'fl_code'    => $code,
			'fl_source'  => self::$params['vendor_id'],
			'fl_state'   => 1,
			'allcats'    => 1,
			'type'       => -1,
			'group'      => self::$params['filters_group'],
			'name_ru-RU' => $name,
		);

		$tmp = $this->getItem($code, 'productFields');

		if ($tmp)
		{
			$item['id']       = $tmp['id'];
			$item['ordering'] = $tmp['ordering'];

			$this->report[5]['Fields updated'] += 1;
		}
		else
		{
			$item['ordering'] = ++self::$counter['productFields'];

			$this->report[5]['Fields imported'] += 1;
		}

		$model->save($item);

		if (!$tmp)
		{
			$tmp = $this->getItem($code, 'productFields');
		}

		$this->importFieldValues($xml->filters->filter, $tmp['id'], 'filter');
	}


	protected function importFieldValues($xml, $field_id, $type)
	{
		static $model;

		if (!$model)
		{
			$model = $this->getModel('productFieldValues');
			$this->setState(0, 'productFieldValues', 'fl_source=' . self::$params['vendor_id']);
		}

		foreach ($xml as $value)
		{
			switch ($type)
			{
				case 'filter':
					$id   = $value->filterid;
					$name = trim((string) $value->filtername);
					break;
				case 'print':
					$id   = $value->name;
					$name = trim((string) $value->description) . " (" . trim((string) $value->name) . ")";
					break;
				default:

					return;
			}

			$code = self::$params['vendor_id'] . '_' . $field_id . '-' . $id;

			$item = array(
				'fl_code'    => $code,
				'fl_source'  => self::$params['vendor_id'],
				'fl_state'   => 1,
				'field_id'   => $field_id,
				'name_ru-RU' => $name,
			);

			$tmp = $this->getItem($code, 'productFieldValues');

			if ($tmp)
			{
				$item['id']       = $tmp['id'];
				$item['ordering'] = $tmp['ordering'];

				$action = 'update';
			}
			else
			{
				$action = 'import';

				$item['ordering'] = ++self::$counter['productFieldValues'];
			}

			$model->save($item);
		}
	}


	protected function importAttributValues($list, $attr_id)
	{
		switch ($attr_id)
		{
			case self::$params['attribs']['size']:
			case self::$params['attribs']['print']:
				break;
			default:
				return;
		}

		static $model;

		if (!$model)
		{
			$model = $this->getModel('AttributValue');
		}

		foreach ($list as $code => $name)
		{
			$item = [
				'fl_code'    => $code,
				'attr_id'    => $attr_id,
				'name_ru-RU' => $name,
			];

			$tmp = $this->getItem($code, 'attrValues');

			if ($tmp)
			{
				$item['value_id']       = $tmp['value_id'];
				$item['value_ordering'] = $tmp['value_ordering'];

				$action = 'update';
			}
			else
			{
				$action = 'import';

				$item['value_ordering'] = ++self::$counter['attrValues'];
			}

			$model->save($item);
		}
	}


	protected function importStock($xml)
	{
		self::logMethodStart(__FUNCTION__);

		$products = $this->getList('products', 'product_ean', 'product_id');
		$attribs  = $this->getList('productsAttr', 'ean', 'product_attr_id');

		foreach ($xml->children() as $name => $item)
		{
			if ($name == 'stock')
			{
				$code = self::$params['vendor_id'] . '_' . (string) $item->product_id;

				if (array_key_exists($code, $products))
				{
					$this->setValues('products', 'product_id=' . $products[$code], 'product_quantity=' . (float) $item->free);

					$this->report[5]['Products quantity updated'] += 1;
				}
				elseif (array_key_exists($code, $attribs))
				{
					$this->setValues('productsAttr', 'product_attr_id=' . $attribs[$code], 'count=' . (float) $item->free);

					$this->report[5]['Attribs quantity updated'] += 1;
				}
				else
				{
					$this->report[5]['Stock quantity skipped'] += 1;
				}
			}
		}

		$this->report();
		self::logMethodComplete(__FUNCTION__);
	}


	protected static function getSrcPath($debug)
	{
		if ($debug)
		{
			return parent::getSrcPath($debug) . 'project111/';
		}

		return 'http://' . self::$params['login'] . ':' . self::$params['password'] . '@api2.gifts.ru/export/v2/catalogue/';
	}
}
