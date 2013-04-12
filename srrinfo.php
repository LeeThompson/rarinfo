<?php

require_once dirname(__FILE__).'/rarinfo.php';

/**
 * SrrInfo class.
 *
 * A simple class for inspecting SRR file data and listing information about
 * the contents and the RAR archives that they cover in pure PHP. Data can be
 * streamed from a file or loaded directly from memory.
 *
 * Example usage:
 *
 * <code>
 *
 *   // Load the SRR file or data
 *   $srr = new SrrInfo;
 *   $srr->open('./foo.srr'); // or $srr->setData($data);
 *   if ($srr->error) {
 *     echo "Error: {$srr->error}\n";
 *     exit;
 *   }
 *
 *   // Get any SRR stored files
 *   $stored = $srr->getStoredFiles();
 *   foreach ($stored as $file) {
 *     echo "{$file['name']} ({$file['size']}):\n";
 *     echo $file['data'];
 *   }
 *
 *   // Inspect the RAR file list
 *   $volumes = $srr->getFileList();
 *   foreach ($volumes as $vol) {
 *     echo "Archive volume: {$vol['name']}\n";
 *     foreach ($vol['files'] as $file) {
 *       if ($file['pass'] == true) {
 *         echo "-- File is passworded: {$file['name']}\n";
 *       }
 *     }
 *   }
 *
 * </code>
 *
 * @author     Hecks
 * @copyright  (c) 2010-2013 Hecks
 * @license    Modified BSD
 * @version    1.7
 */
class SrrInfo extends RarInfo
{
	// ------ Class constants -----------------------------------------------------

	/**#@+
	 * SRR file format values
	 */

	// SRR Block types
	const SRR_BLOCK_MARK      = 0x69;
	const SRR_STORED_FILE     = 0x6a;
	const SRR_RAR_FILE        = 0x71;

	// Flags for SRR Marker block
	const APP_NAME_PRESENT    = 0x0001;

	/**#@-*/

	// ------ Instance variables and methods ---------------------------------------

	/**
	 * Signature for the SRR Marker block.
	 * @var string
	 */
	protected $markerBlock = "\x69\x69\x69";

	/**
	 * List of block names corresponding to SRR block types.
	 * @var array
	 */
	protected $srrBlockNames = array(
		self::SRR_BLOCK_MARK  => 'SRR Marker',
		self::SRR_STORED_FILE => 'Stored File',
		self::SRR_RAR_FILE    => 'RAR File',
	);

	/**
	 * List of block types and Subblock subtypes without bodies.
	 * @var array
	 */
	protected $headersOnly = array(
		'type'    => array(self::BLOCK_FILE),
		'subtype' => array(self::SUBTYPE_RECOVERY),
	);

	/**
	 * Details of the client that created the file/data.
	 * @var string
	 */
	public $client = '';

	/**
	 * Initializes the class instance.
	 *
	 * @return  void
	 */
	public function __construct($file=null, $isFragment=false, array $range=null)
	{
		// Merge the SRR and RAR block names
		$this->blockNames = $this->srrBlockNames + $this->blockNames;

		parent::__construct($file, $isFragment, $range);
	}

	/**
	 * Convenience method that outputs a summary list of the SRR information,
	 * useful for pretty-printing.
	 *
	 * @param   boolean   $full      include full info, e.g. stored file data?
	 * @param   boolean   $skipDirs  should RAR directory entries be skipped?
	 * @return  array     SRR file summary
	 */
	public function getSummary($full=false, $skipDirs=false)
	{
		$summary = array(
			'srr_file'     => $this->file,
			'file_size'    => $this->fileSize,
			'data_size'    => $this->dataSize,
			'client'       => $this->client,
			'stored_files' => $this->getStoredFiles($full),
		);
		$fileList = $this->getFileList($skipDirs);
		$summary['file_count'] = $fileList ? count($fileList) : 0;
		if ($full) {
			$summary['file_list'] = $fileList;
		}

		return $summary;
	}

	/**
	 * Parses the stored blocks and returns a list of the Stored File records,
	 * optionally with the file content data included.
	 *
	 * @param   boolean  $extract  include file data in the result?
	 * @return  mixed  false if no stored files blocks available, or array of records
	 */
	public function getStoredFiles($extract=true)
	{
		if (empty($this->blocks)) {return false;}
		$ret = array();

		foreach ($this->blocks as $block) {
			if ($block['head_type'] == self::SRR_STORED_FILE) {
				$b = array(
					'name' => $block['file_name'],
					'size' => $block['add_size'],
				);
				if ($extract) {
					$b['data'] = $block['file_data'];
				}
				$ret[] = $b;
			}
		}

		return $ret;
	}

	/**
	 * Parses the stored blocks and returns a list of the RAR volume records that the
	 * SRR data covers.
	 *
	 * @param   boolean  $skipDirs  should directory entries be skipped?
	 * @return  mixed  false if no file blocks available, or array of records
	 */
	public function getFileList($skipDirs=false)
	{
		if (empty($this->blocks)) {return false;}
		$list = array();
		$i = -1;

		foreach ($this->blocks as $block) {

			// Start a new RAR volume record
			if ($block['head_type'] == self::SRR_RAR_FILE) {
				$list[++$i] = array('name' => $block['file_name']);

			// Append the file summaries to the current volume record
			} elseif ($block['head_type'] == self::BLOCK_FILE) {
				if ($skipDirs && !empty($block['is_dir'])) {continue;}
				$list[$i]['files'][] = $this->getFileBlockSummary($block);
			}
		}

		return $list;
	}

	// SRR files do not include any file contents
	public function getFileData($filename) {return false;}
	public function saveFileData($filename, $destination) {return false;}

	/**
	 * Parses the SRR data and stores a list of valid blocks locally.
	 *
	 * @return  boolean  false if parsing fails
	 */
	protected function analyze()
	{
		// Find the SRR MARKER block, or abort if none is found
		if (($startPos = $this->findMarkerBlock()) === false) {
			$this->error = 'Could not find Marker block, not a valid SRR file';
			return false;
		}

		// Start at the SRR MARKER block
		$this->seek($startPos);

		// Analyze all valid blocks
		while ($this->offset < $this->length) try {

			// Get the next block header
			$block = $this->getNextBlock();

			// Block type: SRR MARKER
			if ($block['head_type'] == self::SRR_BLOCK_MARK) {
				if ($block['head_flags'] & self::APP_NAME_PRESENT) {
					$block += self::unpack('vapp_name_size', $this->read(2), false);
					$block['app_name'] = $this->read($block['app_name_size']);
					$this->client = $block['app_name'];
				}

			// Block type: STORED FILE
			} elseif ($block['head_type'] == self::SRR_STORED_FILE) {
				$block += self::unpack('vname_size', $this->read(2), false);
				$block['file_name'] = $this->read($block['name_size']);
				$block['file_data'] = $this->read($block['add_size']);

			// Block type: SRR RAR FILE
			} elseif ($block['head_type'] == self::SRR_RAR_FILE) {
				$block += self::unpack('vname_size', $this->read(2), false);
				$block['file_name'] = $this->read($block['name_size']);

			// Default to RAR block processing
			} else {
				parent::processBlock($block);
			}

			// Add current block to the list
			$this->blocks[] = $block;

			// Skip to the next block, if any
			$this->seek($block['next_offset']);

			// Sanity check
			if ($block['offset'] == $this->offset) {
				$this->error = 'Parsing seems to be stuck';
				$this->close();
				return false;
			}

		// No more readable data, or read error
		} catch (Exception $e) {
			if ($this->error) {$this->close(); return false;}
			break;
		}

		// Analysis was successful
		return true;
	}

	/**
	 * Resets the instance variables before parsing new data.
	 *
	 * @return  void
	 */
	protected function reset()
	{
		parent::reset();
		$this->client = '';
	}

} // End SrrInfo class
