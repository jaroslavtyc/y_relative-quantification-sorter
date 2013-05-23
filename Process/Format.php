<?php
namespace RqData\Process;

use RqData\Registry\Errors;
use RqData\RequiredSettings\Options\ColumnsPurpose;

class Format extends Base {

	const DISALLOW_MAXIMUM_CT_FOR_CALIBRATOR = TRUE;
	const CT_DATA_PRECISION = 3;
	const RQ_DATA_PRECISION = 3;
	const TRUNCATE_RQ_ON_CT_EMPTINESS = TRUE;
	const REPORT_MISSING_GENES_DATA = FALSE;
	const REPORT_DUPLICIT_GENES_DATA = TRUE;
	const ORDER_GENES_ALPHABETICALY = TRUE;
	const ORDER_OBJECTS_ALPHABETICALY = TRUE;
	const CALIBRATOR_COLOUR = TRUE;
	const CALIBRATOR_FIRST = TRUE;

	protected $inputValues;
	protected $formedData;
	protected $listOfGeneNames;
	protected $nameOfCalibrator;
	protected $formedHeader;

	public function __construct(InputValues $inputValues, Errors $errors) {
		parent::__construct($errors);
		$this->inputValues = $inputValues;
	}

	public function process() {
		$this->getInputValues()->process();
		$this->formatData();
	}

	public function getTimeTempnameKey() {
		return $this->getInputValues()->getTimeTempnameKey();
	}

	public function getFormedHeader() {
		if (!isset($this->formedHeader)) {
			$this->fillFormedHeader();
		}
		return $this->formedHeader;
	}

	/**
	 * @return InputValues
	 */
	protected function getInputValues() {
		return $this->inputValues;
	}

	protected function formatData() {
		$inputDataPositions = $this->getInputDataPosition();
		$columnsPurposeData = $this->getColumnsPurpose()->getData();
		$subjectNamePosition = $inputDataPositions[ColumnsPurpose::SUBJECT_NAME];
		$geneNamePosition = $inputDataPositions[ColumnsPurpose::GENE_NAMES];
		$ctDataPosition = $inputDataPositions[ColumnsPurpose::CT_VALUES];
		if ($this->getColumnsPurpose()->isRqdataInvolved()) {
			$rqDataPosition = $inputDataPositions[ColumnsPurpose::RQ_VALUES];
		}
		$preformedData = array();
		$listOfGeneNames = array();
		$calibratorCandidates = array();
		$this->parseInputValues($preformedData, $calibratorCandidates, $subjectNamePosition, $geneNamePosition, $ctDataPosition,
		$rqDataPosition, $columnsPurposeData, $listOfGeneNames);
		$orderedListOfGeneNames = $this->orderListOfGeneNames($listOfGeneNames);
		$nameOfCalibrator = FALSE;
		if ($this->getColumnsPurpose()->isRqdataInvolved()) {
			$nameOfCalibrator = $this->determineCalibratorName($calibratorCandidates);
		} else {
			$nameOfCalibrator = $this->extendingSettings['calibrator'];
			$this->checkUserGivenCalibratorValidity(
				$nameOfCalibrator,
				$preformedData,
				$orderedListOfGeneNames,
				$this->extendingSettings['referenceGenes']
			);
		}
		$this->checkIfIsMaximumCtValueOfCalibratorAllowed($calibratorCandidates, $nameOfCalibrator);
		if (!$this->getColumnsPurpose()->isRqdataInvolved()) {
			$this->addRqData($preformedData, $orderedListOfGeneNames);
		}
		$formedData = $this->getFormedData($preformedData, $nameOfCalibrator);
		if ($this->errors->dejPocetNovychChyb() > 0) {
			throw new FormatingDataFailedDueToUserMistake;
		}
		$this->formedData = $formedData;
		$this->listOfGeneNames = $orderedListOfGeneNames;
		$this->nameOfCalibrator = $nameOfCalibrator;
		$this->fillInputHeader(
			$subjectNamePosition,
			$ctDataPosition,
			($this->getColumnsPurpose()->isRqdataInvolved()
				? $rqDataPosition
				: FALSE
			)
		);
	}

	protected function parseInputValues(
		&$preformedData,
		&$calibratorCandidates,
		$subjectNamePosition,
		$geneNamePosition,
		$ctDataPosition,
		$rqDataPosition,
		$columnsPurposeData,
		$listOfGeneNames
	) {
		foreach ($this->getInputValues()->getInputData() as $rowIndex => $row) {
			if (!$this->checkSubjectNamePresence($row, $subjectNamePosition, $rowIndex, $columnsPurposeData)
				|| !$this->checkGeneNamePresence($row, $geneNamePosition, $rowIndex, $columnsPurposeData)
			) {
				continue;
			}
			if (!isset($preformedData[$row[$subjectNamePosition]])) {
				$preformedData[ $row[$subjectNamePosition] ] = array();
				$calibratorCandidates[ $row[$subjectNamePosition] ] = array('count' => 0);
			}
			$currentSubject = &$preformedData[ $row[$subjectNamePosition] ];
			$currentCalibratorCandidate = &$calibratorCandidates[ $row[$subjectNamePosition] ];
			if (!isset($currentSubject[ $row[$geneNamePosition] ])) {
				$currentSubject[ $row[$geneNamePosition] ] = array();
			}
			if (!in_array($row[$geneNamePosition], $listOfGeneNames)) {
				$listOfGeneNames[] = $row[$geneNamePosition];
			}
			$currentGeneData = &$currentSubject[ $row[$geneNamePosition] ];
			$duplicitGenesData = array();
			$this->addCtDataToRow($currentGeneData, $duplicitGenesData, $ctDataPosition, $row, $currentCalibratorCandidate, $rowIndex, $subjectNamePosition, $geneNamePosition,$columnsPurposeData);
			if ($this->getColumnsPurpose()->isRqdataInvolved()) {
				$this->addRqDataToRow($currentGeneData, $duplicitGenesData, $rqDataPosition, $row, $currentCalibratorCandidate, $rowIndex, $subjectNamePosition, $geneNamePosition,$columnsPurposeData);
			}
			if (self::REPORT_DUPLICIT_GENES_DATA && sizeof($duplicitGenesData) > 0) {
				$this->handleDuplicitGenesData($duplicitGenesData);
			}
		}
	}

	protected function handleDuplicitGenesData($duplicitGenesData, $rowIndex, $row, $subjectNamePosition, $geneNamePosition) {
		$this->errors->zapamatujChybu(
			sprintf(
				'Na řádku %d je duplicitní informace %s pro subjekt %s a gen %s',
				($rowIndex +1),
				implode(' a ', $duplicitGenesData),
				$row[$subjectNamePosition],
				$row[$geneNamePosition]
			),
			'Formát'
		);
	}

	protected function addCtDataToRow(&$currentGeneData, &$duplicitGenesData, $ctDataPosition, $row, $currentCalibratorCandidate, $rowIndex, $subjectNamePosition, $geneNamePosition,$columnsPurposeData) {
		if (!isset($currentGeneData[ColumnsPurpose::CT_VALUES])) {
			if (isset($row[$ctDataPosition]) && $row[$ctDataPosition] !== '') {
				if (self::DISALLOW_MAXIMUM_CT_FOR_CALIBRATOR
					&& $row[$ctDataPosition] >= MaximalCtValueSettings::VALUE
				) {
					if (empty($currentCalibratorCandidate['maximum_reached'])) {
						$currentCalibratorCandidate['maximum_reached'] = array(
							'rowIndex' => $rowIndex,
							'geneName' => $row[$geneNamePosition]
						);
					}
				}
				//CT data placing and rounding
				$currentGeneData[ColumnsPurpose::CT_VALUES] = round($row[$ctDataPosition], self::CT_DATA_PRECISION);
			} else {
				$currentGeneData[ColumnsPurpose::CT_VALUES] = '';
				if (self::REPORT_MISSING_GENES_DATA) {
					$this->errors->zapamatujChybu(
						sprintf(
							'Na řádku %d chybí informace %s pro subjekt %s a gen %s',
							($rowIndex +1),
							current($columnsPurposeData[ColumnsPurpose::CT_VALUES]),
							$row[$subjectNamePosition],
							$row[$geneNamePosition]
						),
						'Formát'
					);
				}
			}
		} elseif (self::REPORT_DUPLICIT_GENES_DATA) {
			$duplicitGenesData[] = current($columnsPurposeData[ColumnsPurpose::CT_VALUES]);
		}
	}

	protected function addRqDataToRow(&$currentGeneData, &$duplicitGenesData, $rqDataPosition, $row, $currentCalibratorCandidate, $rowIndex, $subjectNamePosition, $geneNamePosition,$columnsPurposeData) {
		if (isset($currentGeneData[ColumnsPurpose::RQ_VALUES])) {
			if (self::REPORT_DUPLICIT_GENES_DATA) {
				$duplicitGenesData[] = current($columnsPurposeData[ColumnsPurpose::RQ_VALUES]);
			}
		} else {
			if (isset($row[$rqDataPosition]) && $row[$rqDataPosition] !== '') {
				// RQ data placing
				$currentGeneData[ColumnsPurpose::RQ_VALUES] = $row[$rqDataPosition];
				//Healthy candidate
				if ($currentGeneData[ColumnsPurpose::RQ_VALUES] == 1) {
					$currentCalibratorCandidate['count']++;
				}
			} else {
				$currentGeneData[ColumnsPurpose::RQ_VALUES] = '';
				if (self::REPORT_MISSING_GENES_DATA) {
					$this->errors->zapamatujChybu(
						sprintf(
							'Na řádku %d chybí informace %s pro subjekt %s a gen %s',
							($rowIndex +1),
							current($columnsPurposeData[ColumnsPurpose::RQ_VALUES]),
							$row[$subjectNamePosition],
							$row[$geneNamePosition]
						),
						'Formát'
					);
				}
			}
		}
	}

	protected function checkSubjectNamePresence($row, $subjectNamePosition, $rowIndex, $columnsPurposeData) {
		$result = TRUE;
		if (!isset($row[$subjectNamePosition])) {
			$this->errors->zapamatujChybu(
				sprintf('Na řádku %d chybí informace %s', ($rowIndex +1), current($columnsPurposeData[ColumnsPurpose::SUBJECT_NAME])),
				'Formát'
			);
			$result = FALSE;
		}
		return $result;
	}

	protected function checkGeneNamePresence($row, $geneNamePosition, $rowIndex, $columnsPurposeData) {
		$result = TRUE;
		if (!isset($row[$geneNamePosition])) {
			$this->errors->zapamatujChybu(
				sprintf('Na řádku %d chybí informace %s', ($rowIndex +1), current($columnsPurposeData[ColumnsPurpose::GENE_NAMES])),
				'Formát'
			);
			$result = FALSE;
		}
		return $result;
	}

	protected function getInputDataPosition() {
		$inputDataPositions = array();
		foreach(array_keys($this->getColumnsPurpose()->getData()) as $keyIndex => $intBinKey){
			$inputDataPositions[$intBinKey] = $keyIndex;
		}
		return $inputDataPositions;
	}

	private function fillFormedHeader() {
		$this->formedHeader = array(
			ColumnsPurpose::SUBJECT_NAME => 'subject',
			ColumnsPurpose::CT_VALUES => 'CT',
			ColumnsPurpose::RQ_VALUES => 'RQ',
		);
	}

	private function fillInputHeader(
		$subjectNamePosition,
		$ctDataPosition,
		$rqDataPosition
	) {
		if (is_array($this->formedHeader)) {
			$inputHeader = $this->getInputValues()->getInputHeader();
			if (isset($inputHeader[$subjectNamePosition])) {
				$this->formedHeader[ColumnsPurpose::SUBJECT_NAME] = $inputHeader[$subjectNamePosition];
			}

			if (isset($inputHeader[$ctDataPosition])) {
				$this->formedHeader[ColumnsPurpose::CT_VALUES] = $inputHeader[$ctDataPosition];
			}

			if ($this->getColumnsPurpose()->isRqdataInvolved()) {
				if (isset($inputHeader[$rqDataPosition])) {
					$this->formedHeader[ColumnsPurpose::RQ_VALUES] = $inputHeader[$rqDataPosition];
				}
			}
		}
	}

	private function orderListOfGeneNames($listOfGeneNames) {
		reset($listOfGeneNames);
		if (self::ORDER_GENES_ALPHABETICALY) {
			natcasesort($listOfGeneNames);
		}

		return $listOfGeneNames;
	}

	private function checkUserGivenCalibratorValidity(
		$nameOfUserDefinedCalibrator,
		array $preformedData,
		array $listOfGeneNames,
		array $referenceGenes
	) {
		$invalid = FALSE;
		if (!array_key_exists($nameOfUserDefinedCalibrator, $preformedData)) {
			$this->errors->zapamatujChybu(
				sprintf('s názvem "%s" není zastoupen', $nameOfUserDefinedCalibrator),
				\RqData\RequiredSettings\File\Calibrator::HUMAN_NAME
			);
			$invalid = TRUE;
		}
		$notInvolvedReferenceGenes = array();
		foreach ($referenceGenes as $referenceGene) {
			if (!in_array($referenceGene, $listOfGeneNames)) {
				$notInvolvedReferenceGenes[] = $referenceGene;
			}
		}
		if ($notInvolvedReferenceGenes) {
			foreach ($notInvolvedReferenceGenes as &$notInvolved) {
				$notInvolved = '"' . $notInvolved . '"';
			}
			$this->errors->zapamatujChybu(
				sprintf('ve zdrojovém souboru chybí %s', implode(', ', $notInvolvedReferenceGenes)),
				\RqData\RequiredSettings\File\ReferenceGenes::HUMAN_NAME
			);
			$invalid = TRUE;
		}
		if ($invalid) {
			throw new ByUserGivenCalibratorIsNotValid;
		}
	}

	private function determineCalibratorName($calibratorCandidates) {
		$calibratorValues = -1;
		foreach ($calibratorCandidates as
			$calibratorCandidateName => $calibratorCandidateInformation
		) {
			if ($calibratorCandidateInformation['count'] > 0) {
				if ($calibratorCandidateInformation['count'] > $calibratorValues) {
					$nameOfCalibrator = $calibratorCandidateName;
					$calibratorValues = $calibratorCandidateInformation['count'];
				} elseif ($calibratorCandidateInformation['count'] == $calibratorValues) {
					$nameOfCalibrator = FALSE;
					$calibratorValues = -1;
				}
			}
		}

		return $nameOfCalibrator;
	}

	private function getFormedData($preformedData, $nameOfCalibrator) {
		foreach ($preformedData as &$subjectData) {
			foreach ($subjectData as &$currentGeneData) {
				if (self::TRUNCATE_RQ_ON_CT_EMPTINESS
					&& $currentGeneData[ColumnsPurpose::CT_VALUES] === ''
				) {
					$currentGeneData[ColumnsPurpose::RQ_VALUES] = '';
				} else {
					$currentGeneData[ColumnsPurpose::RQ_VALUES] = round(
						$currentGeneData[ColumnsPurpose::RQ_VALUES],
						self::RQ_DATA_PRECISION
					);

					// optional RQ value replacement in dependency on CT value
					if (\RqData\Process\Settings::COUNT_CONSEQUENCES_OF_CT_MAXIMUM) {
						if ($currentGeneData[ColumnsPurpose::CT_VALUES] >=
							MaximalCtValueSettings::VALUE
						) {
							if ($currentGeneData[ColumnsPurpose::RQ_VALUES] <
								$this->optionalSettings[RqValueEdge::CODE]
							) {
								$currentGeneData[ColumnsPurpose::RQ_VALUES] =
									$this->optionalSettings[ReplacementValueUnderMaximum::CODE];
							} else {
								$currentGeneData[ColumnsPurpose::RQ_VALUES] =
									$this->optionalSettings[ReplacementValueOverMaximum::CODE];
							}
						}
					}
				}
			}
		}

		$reformedData = array();
		//healthy row should be first
		if (self::CALIBRATOR_FIRST && $nameOfCalibrator !== FALSE) {
			$reformedData[$nameOfCalibrator] = $preformedData[$nameOfCalibrator];
			unset($preformedData[$nameOfCalibrator]);
		}

		//ordering objects has lower priority then healthy first
		if (self::ORDER_OBJECTS_ALPHABETICALY) {
			$orderedObjectNames = array_keys($preformedData);
			natcasesort($orderedObjectNames);
			foreach ($orderedObjectNames as $orderedObjectName) {
				$reformedData[$orderedObjectName] = $preformedData[$orderedObjectName];
				unset($preformedData[$orderedObjectName]);
			}
		} else {
			foreach ($preformedData as $valueName => $value) {
				$reformedData[$valueName] = $value;
				unset($preformedData[$valueName]);
			}
		}

		return $reformedData;
	}

	private function checkIfIsMaximumCtValueOfCalibratorAllowed($calibratorCandidates, $nameOfCalibrator) {
		if (self::DISALLOW_MAXIMUM_CT_FOR_CALIBRATOR && $nameOfCalibrator !== FALSE) {
			if (!empty($calibratorCandidates[$nameOfCalibrator]['maximum_reached'])) {
				$maximum = $calibratorCandidates[$nameOfCalibrator]['maximum_reached'];
				$this->errors->zapamatujChybu(
					sprintf(
						'Hodnota Ct kalibrátoru "%s" dosáhla nepovolené hodnoty %s pro detektor "%s" na řádku %d',
						$nameOfCalibrator,
						MaximalCtValueSettings::VALUE,
						$maximum['geneName'],
						($maximum['rowIndex'] +1)
					),
					'Kalibrátor'
				);
				throw new CalibratorMaximumCtValueOverflow;
			}
		}
	}


	/**
	 * Calculate values, needed for later RQ data
	 *
	 * @param array &$data
	 * @param string $calibratorName
	 *
	 * return void
	 */
	private function addRqData(&$data, $listOfGeneNames) {
		if (!isset($this->extendingSettings['calibrator'])) {
			throw new Exception('Undefined by-user-set calibrator');
		}

		if (empty($this->extendingSettings['referenceGenes'])) {
			throw new Exception('Undefined by-user-set reference genes');
		}

		$calibratorName = $this->extendingSettings['calibrator'];
		$referenceGenes = $this->extendingSettings['referenceGenes'];
		$calibratorData = $data[$calibratorName]; //row with selected calibrator data
		foreach ($data as $subjectName => &$subjectData) {
			$subjectPreRqData = array();
			$subjectNormalizingFactorBase = 1;
			foreach ($listOfGeneNames as $geneName) {
				$subjectPreRqData[$subjectName][$geneName] = array();
				if (!isset($calibratorData[$geneName])) {
					$this->errors->zapamatujChybu(
						'chybí informace o genu ' . $geneName,
						\RqData\RequiredSettings\File\Calibrator::HUMAN_NAME
					);

					return FALSE;
				}

				if (!isset($subjectData[$geneName])) {
					//subject does not contain information about actual gene
					continue;
				}

				$calibratorCtValue = $calibratorData[$geneName][ColumnsPurpose::CT_VALUES];
				$subjectPreRqData[$geneName] = pow(2,
					- ($subjectData[$geneName][ColumnsPurpose::CT_VALUES] -
						$calibratorCtValue)
				);

				if (in_array($geneName, $referenceGenes)) {
					$subjectNormalizingFactorBase *= $subjectPreRqData[$geneName];
				}
			}

			$subjectNormalizingFactor = pow(
				$subjectNormalizingFactorBase,
				1/sizeof($referenceGenes)
			);
			foreach ($listOfGeneNames as $geneName) {
				$subjectData[$geneName][ColumnsPurpose::RQ_VALUES] =
					$subjectPreRqData[$geneName] / $subjectNormalizingFactor;
			}
		}
	}
}

class ByUserGivenCalibratorIsNotValid extends \RqData\Debugging\UserException {}
class CalibratorMaximumCtValueOverflow extends \RqData\Debugging\UserException {}
class FormatingDataFailedDueToUserMistake extends \RqData\Debugging\UserException {}