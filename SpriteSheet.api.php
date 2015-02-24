<?php
/**
 * SpriteSheet
 * SpriteSheet API
 *
 * @author		Alexia E. Smith
 * @license		LGPL v3.0
 * @package		SpriteSheet
 * @link		https://github.com/CurseStaff/SpriteSheet
 *
 **/

class SpriteSheetAPI extends ApiBase {
	/**
	 * API Initialized
	 *
	 * @var		boolean
	 */
	private $initialized = false;

	/**
	 * Initiates some needed classes.
	 *
	 * @access	public
	 * @return	void
	 */
	private function init() {
		if (!$this->initialized) {
			global $wgUser, $wgRequest;
			$this->wgUser		= $wgUser;
			$this->wgRequest	= $wgRequest;

			$this->initialized = true;
		}
	}

	/**
	 * Main Executor
	 *
	 * @access	public
	 * @return	void	[Outputs to screen]
	 */
	public function execute() {
		$this->init();

		$this->params = $this->extractRequestParams();

		if ($this->wgUser->getId() < 1 || User::isIP($this->wgUser->getName()) || $this->wgUser->curse_id < 1) {
			$this->dieUsageMsg(['invaliduser', $this->params['do']]);
		}

		switch ($this->params['do']) {
			case 'saveSpriteSheet':
				$response = $this->saveSpriteSheet();
				break;
			case 'saveSpriteName':
				$response = $this->saveSpriteName();
				break;
			case 'getAllSpriteNames':
				$response = $this->getAllSpriteNames();
				break;
			default:
				$this->dieUsageMsg(['invaliddo', $this->params['do']]);
				break;
		}

		foreach ($response as $key => $value) {
			$this->getResult()->addValue(null, $key, $value);
		}
	}

	/**
	 * Requirements for API call parameters.
	 *
	 * @access	public
	 * @return	array	Merged array of parameter requirements.
	 */
	public function getAllowedParams() {
		return [
			'do' => [
				ApiBase::PARAM_TYPE		=> 'string',
				ApiBase::PARAM_REQUIRED => true
			],
			'form' => [
				ApiBase::PARAM_TYPE		=> 'string',
				ApiBase::PARAM_REQUIRED => false
			],
			'type' => [
				ApiBase::PARAM_TYPE		=> 'string',
				ApiBase::PARAM_REQUIRED => false
			],
			'values' => [
				ApiBase::PARAM_TYPE		=> 'string',
				ApiBase::PARAM_REQUIRED => false
			],
			'spritesheet_id' => [
				ApiBase::PARAM_TYPE		=> 'integer',
				ApiBase::PARAM_REQUIRED => false
			]
		];
	}

	/**
	 * Descriptions for API call parameters.
	 *
	 * @access	public
	 * @return	array	Merged array of parameter descriptions.
	 */
	public function getParamDescription() {
		return [
			'do'				=> 'Action to take.',
			'form'				=> 'Form data from a sprite sheet editor form.',
			'type'				=> 'Sprite or Slice',
			'values'			=> 'Values for the sprite or slice being saved.',
			'spritesheet_id'	=> 'SpriteSheet ID of the the SpriteSheet to load.'
		];
	}

	/**
	 * Save Sprite Sheet information.
	 *
	 * @access	private
	 * @return	array	Success, Messages
	 */
	private function saveSpriteSheet() {
		$success = false;
		$message = 'ss_api_unknown_error';

		if (!$this->wgUser->isAllowed('edit_sprites')) {
			$message = 'ss_api_no_permission';
			return [
				'success' => $success,
				'message' => wfMessage($message)->text()
			];
		}

		if ($this->wgRequest->wasPosted()) {
			parse_str($this->params['form'], $form);
			if ($form['spritesheet_id'] > 0) {
				$spriteSheet = SpriteSheet::newFromId($form['spritesheet_id']);
			} else {
				$title = Title::newFromDBKey($form['page_title']);
				if ($title !== null) {
					$spriteSheet = SpriteSheet::newFromTitle($title);
				} else {
					$message = 'ss_api_bad_title';
				}
			}
			if ($spriteSheet !== false) {
				$spriteSheet->setColumns($form['sprite_columns']);
				$spriteSheet->setRows($form['sprite_rows']);
				$spriteSheet->setInset($form['sprite_inset']);

				$success = $spriteSheet->save();

				if ($success) {
					$log = new LogPage('sprite');
					$log->addEntry(
						'sheet',
						$spriteSheet->getTitle(),
						$comment,
						[],
						$this->wgUser
					);

					$message = 'ss_api_okay';
				} else {
					$message = 'ss_api_fatal_error_saving';
				}
			} else {
				$message = 'ss_api_fatal_error_loading';
			}
		} else {
			$message = 'ss_api_must_be_posted';
		}

		$return = [
			'success' => $success,
			'message' => wfMessage($message)->text()
		];

		if ($success) {
			$return['spriteSheetId'] = $spriteSheet->getId();
		}

		return $return;
	}

	/**
	 * Save a named sprite/slice.
	 *
	 * @access	private
	 * @return	void
	 */
	private function saveSpriteName() {
		$success = false;
		$message = 'ss_api_unknown_error';

		if (!$this->wgUser->isAllowed('edit_sprites')) {
			$message = 'ss_api_no_permission';
			return [
				'success' => $success,
				'message' => wfMessage($message)->text()
			];
		}

		if ($this->wgRequest->wasPosted()) {
			$values = @json_decode($this->params['values'], true);
			parse_str($this->params['form'], $form);

			if ($form['spritesheet_id'] > 0) {
				$spriteSheet = SpriteSheet::newFromId($form['spritesheet_id']);
			} else {
				$title = Title::newFromDBKey($form['page_title']);
				if ($title !== null) {
					$spriteSheet = SpriteSheet::newFromTitle($title);
				} else {
					$message = 'ss_api_bad_title';
				}
			}
			if ($spriteSheet !== false) {
				$spriteName = $spriteSheet->getSpriteName($form['sprite_name']);
				$validName = true;

				if (!$spriteName->isNameValid()) {
					$message = 'ss_api_invalid_sprite_name';
					$validName = false;
				}

				if ($validName) {
					switch ($this->params['type']) {
						case 'sprite':
							if ($spriteSheet->validateSpriteCoordindates($values['xPos'], $values['yPos'])) {
								$spriteName->setValues($values);
								$spriteName->setType('sprite');

								$success = $spriteName->save();

								if ($success) {
									$log = new LogPage('sprite');
									$log->addEntry(
										'sprite',
										$spriteSheet->getTitle(),
										$comment,
										[$spriteName->getName()],
										$this->wgUser
									);

									$message = 'ss_api_okay';
								} else {
									$message = 'ss_api_fatal_error_saving';
								}
							} else {
								$message = 'ss_api_invalid_coordinates';
							}
							break;
						case 'slice':
							if ($spriteSheet->validateSlicePercentages($values['xPercent'], $values['yPercent'], $values['widthPercent'], $values['heightPercent'])) {
								$spriteName->setValues($values);
								$spriteName->setType('slice');

								$success = $spriteName->save();

								if ($success) {
									$log = new LogPage('sprite');
									$log->addEntry(
										'slice',
										$spriteSheet->getTitle(),
										$comment,
										[$spriteName->getName()],
										$this->wgUser
									);

									$message = 'ss_api_okay';
								} else {
									$message = 'ss_api_fatal_error_saving';
								}
							} else {
								$message = 'ss_api_invalid_precentages';
							}
							break;
						default:
							break;
					}
				}
			} else {
				$message = 'ss_api_fatal_error_loading';
			}
		} else {
			$message = 'ss_api_must_be_posted';
		}

		$return = [
			'success' => $success,
			'message' => wfMessage($message)->text()
		];

		if ($success) {
			$return['tag'] = $spriteName->getParserTag();
		}

		return $return;
	}

	/**
	 * Function Documentation
	 *
	 * @access	private
	 * @return	void
	 */
	private function getAllSpriteNames() {
		$spriteSheetId = intval($this->params['spritesheet_id']);
		if ($spriteSheetId > 0) {
			$spriteSheet = SpriteSheet::newFromId($spriteSheetId);
		} else {
			$message = 'ss_api_bad_title';
		}

		$data = [];
		if (!empty($spriteSheet) && $spriteSheet->exists()) {
			$spriteNames = $spriteSheet->getAllSpriteNames();

			asort($spriteNames);

			foreach ($spriteNames as $name => $spriteName) {
				$data[$spriteName->getName()] = [
					'id'		=> $spriteName->getId(),
					'name'		=> $spriteName->getName(),
					'type'		=> $spriteName->getType(),
					'values'	=> $spriteName->getValues(),
					'tag'		=> $spriteName->getParserTag(),
				];
			}

			$message = 'ss_api_okay';
		} else {
			$message = 'ss_api_fatal_error_loading';
		}

		$return = [
			'data' => $data,
			'message' => wfMessage($message)->text()
		];

		return $return;
	}

	/**
	 * Get version of this API Extension.
	 *
	 * @access	public
	 * @return	string	API Extension Version
	 */
	public function getVersion() {
		return '1.0';
	}

	/**
	 * Return a ApiFormatJson format object.
	 *
	 * @access	public
	 * @return	object	ApiFormatJson
	 */
	public function getCustomPrinter() {
		return $this->getMain()->createPrinterByName('json');
	}
}
