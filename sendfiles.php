<?php

# Class to facilitate sending of large files between internal and external users
require_once ('frontControllerApplication.php');
class sendfiles extends frontControllerApplication
{
	# Function to assign defaults additional to the general application defaults
	public function defaults ()
	{
		# Specify available arguments as defaults or as NULL (to represent a required argument)
		$defaults = array (
			'useDatabase'			=> false,
			'div'					=> 'sendfiles',
			'institution'			=> NULL,
			'members'				=> array (),	// Simple array of usernames
			'memberDescription'		=> 'member',
			'membersDescription'	=> 'members',
			'data'					=> '/data/',
			'days'					=> 14,			// Number of days that downloaded files are available for, and for which invites are valid
			'keyLength'				=> 16,			// NB Do not change this after the site has gone live, as previously-uploaded files will then not match
			'onceOnlyInvite'		=> true,		// Whether the invite is once-only or can continue to be used during the validity period after a successful upload
			'umaskPermissions'		=> 0002,
			'mkdirPermissions'		=> 02770,
			'chmodPermissions'		=> 0660,
		);
		
		# Return the defaults
		return $defaults;
	}
	
	
	# Function to assign supported actions
	public function actions ()
	{
		# Specify additional actions
		$actions = array (
			'upload' => array (
				'tab' => 'Send a file',
				'icon' => 'add',
				'url' => 'upload/',
				'description' => 'Send a file to someone',
				'authentication' => false,
			),
			'download' => array (
				'tab' => 'Collect a file',
				'icon' => 'page_white_go',
				'url' => 'download/',
				'description' => 'Collect a file that someone else has added',
				'authentication' => false,
			),
			'filedelete' => array (
				'usetab' => 'download',
				'url' => 'download/%1/delete.html',
				'description' => 'Delete a retrieved file',
				'authentication' => true,
			),
			'filetransfer' => array (
				'url' => 'download/%1/file',
				'description' => false,
				'authentication' => false,
				'export' => true,
			),
			'invite' => array (
				'tab' => 'Send invite',
				'icon' => 'email',
				'url' => 'invite/',
				'description' => 'Send an invite to an external person to let them send you a file',
				'authentication' => false,
			),
			'invitesend' => array (
				'usetab' => 'invite',
				'url' => 'invite/',
				'description' => 'Send an invite to an external person to let them send you a file',
				'authentication' => true,
			),
			'uploadinternal' => array (
				'usetab' => 'upload',
				'url' => 'upload/internal/',
				'description' => 'Send a file to someone',
				'authentication' => true,
			),
			'uploadexternal' => array (
				'usetab' => 'upload',
				'url' => 'upload/external/',
				'description' => 'Send a file to someone',
				'authentication' => false,
			),
		);
		
		# Return the actions
		return $actions;
	}
	
	
	
	# Additional main processing
	public function main ()
	{
		# Determine if the user is a member
		$this->isMember = ($this->user && in_array ($this->user, $this->settings['members']));
		
		# Ensure there is a (slash-terminated) data directory
		if (!$this->dataDirectory = $this->directoryOk ($this->settings['data'])) {return false;}
		
		# Ensure there is a (slash-terminated) invites sub-directory
		if (!$this->invitesDirectory = $this->directoryOk ($this->settings['data'] . 'invites/')) {return false;}
		
		# Find all the files in the data directory
		$this->currentFiles = $this->fileList ($this->dataDirectory);
		
		# Find all the invites
		$this->invites = $this->fileList ($this->invitesDirectory);
		
	}
	
	
	# Function to ensure there is a (slash-terminated) data directory that is readable and writable
	private function directoryOk ($location)
	{
		# Determine the location
		$directory = $_SERVER['DOCUMENT_ROOT'] . $location;
		
		# Ensure it is slash-terminated
		$directory = ((substr ($directory, -1) == '/') ? $directory : $directory . '/');
		
		# Create the directory if required
		if (!is_dir ($directory)) {
			#!# Error handling (though will get caught below anyway)
			umask ($this->settings['umaskPermissions']);
			mkdir ($directory, $this->settings['mkdirPermissions'], true);
		}
		
		# Ensure the data directory is readable and writable
		if (!is_readable ($directory)) {
			echo $this->reportError ("The directory:\n{$directory}\nis not readable.");
			return false;
		}
		if (!is_writable ($directory)) {
			echo $this->reportError ("The directory:\n{$directory}\nis not writable.");
			return false;
		}
		
		# Return the result
		return $directory;
	}
	
	
	# Function to create a list of uploaded files
	private function fileList ($directory, $reportNonmatching = false)
	{
		# Get the file listing
		#!# skipUnreadableFiles is a bit of a shortcut and means problems aren't reported
		require_once ('directories.php');
		$files = directories::listFiles ($directory, $supportedFileTypes = array (), $directoryIsFromRoot = true, $skipUnreadableFiles = true);
		
		# Loop through each file so that the extensions in the name can be split out
		foreach ($files as $filename => $attributes) {
			
			# Skip the invites directory
			if ($filename == 'invites' && $attributes['directory']) {
				unset ($files[$filename]);
				continue;
			}
			
			# Do the match, and report to the admin a non-fatal error if a non-matching file is found
			if (!preg_match ('/^(.+).([a-f0-9]{' . $this->settings['keyLength'] . '})\.([0-9]{10})\.(.+)$/', $filename, $matches)) {
				if ($reportNonmatching) {
					$this->reportError ("A file with name:\n{$filename}\nin the directory:\n{$directory}\ndoes not match the expected pattern.");
				}
				unset ($files[$filename]);
				continue;
			}
			
			# Don't bother doing any more processing if just reporting non-matching files
			if ($reportNonmatching) {continue;}
			
			# Ensure the name is the full name without the final file extension chopped off
			$files[$filename]['name'] = $filename;
			
			# Add the match details to the array
			$files[$filename]['file'] = $matches[1];
			$files[$filename]['key'] = $matches[2];
			$files[$filename]['timestamp'] = $matches[3];
			$files[$filename]['user'] = $matches[4];
			
			# Add in the timeout time
			$files[$filename]['timeout'] = $this->availableUntil ($files[$filename]['timestamp']);
			$files[$filename]['timeoutFormatted'] = $this->availableUntil ($files[$filename]['timestamp'], true);
			
			# For deletion, allow a day past the timeout (i.e. allow a day's downloading time)
			$files[$filename]['deletion'] = $this->availableUntil ($files[$filename]['timestamp'] + (1 * 24 * 60 * 60));
			
			# Actually delete the file if it is past the deletion time
			$now = time ();
			if ($now >= $files[$filename]['deletion']) {
				unlink ($directory . $filename);
			}
			
			# Exclude the file from the listing if it is past the timeout (which will catch deletion)
			if ($now >= $files[$filename]['timeout']) {
				unset ($files[$filename]);
			}
		}
		
		# Don't bother doing any more processing if just reporting non-matching files
		if ($reportNonmatching) {return;}
		
		# Re-key by the key name, since that is the primary identifier in this system
		$filesByKey = array ();
		foreach ($files as $filename => $attributes) {
			$key = $attributes['key'];
			$filesByKey[$key] = $attributes;	// This is acceptable because the system uses unique keys, so overwriting will not happen (unless the directory has been tampered with)
		}
		
		# Return the file list
		return $filesByKey;
	}
	
	
	# Register a shutdown (actually post-action) function
	#!# Aim to remove this
	public function shutdown ()		// called by frontControllerapplication
	{
		# Report files that should not be present; the non-match reporting must be done after all processing, otherwise it will result in partially-moved files from the uploader being complained about
		$this->fileList ($this->dataDirectory, $reportNonmatching = true);
	}
	
	
	
	# Home page
	public function home ()
	{
		# Construct the HTML
		$html  = "\n<p>Welcome to Sendfiles, which lets {$this->settings['membersDescription']} send and receive large files that are impractical to send by e-mail.</p>";
		$html .= "\n<p>Please use the menu links to send or retrieve files.</p>";
		
		# Show the HTML
		echo $html;
	}
	
	
	# Upload introduction page
	public function upload ()
	{
		# Construct the HTML
		if ($this->isMember) {
			$html  = "<p>You appear to be a " . ucfirst ($this->settings['memberDescription']) . ", so can send files directly.</p>";
			$html .= "<p class=\"comment\">(By comparison, external members require an invitation from a " . ucfirst ($this->settings['memberDescription']) . ".)</p>";
			$html .= "<p class=\"download\"><a class=\"actions\" href=\"{$this->baseUrl}/upload/internal/\"><img src=\"/images/icons/email.png\" alt=\"*\" class=\"icon\" /> <strong>Send a file</strong></a></p>";
		} else {
			$html  = "
			<p>Are you:</p>
			<ul>
				<li><a href=\"{$this->baseUrl}/upload/internal/\">A " . $this->settings['memberDescription'] . "</a></li>
				<li><a href=\"{$this->baseUrl}/upload/external/\">External</a></li>
			</ul>
			";
		}
		
		# Show the HTML
		echo $html;
	}
	
	
	# File transfer
	public function filetransfer ()
	{
		return $this->download (true);
	}
	
	
	# Function to validate a key
	private function validateKey ($files, $welcomeMessage = false, $downloadMode = true, $deleteMode = false)
	{
		# If no key has been supplied, show the type selection page
		if (!isSet ($_GET['key'])) {
			echo $welcomeMessage;
			return false;
		}
		
		# Ensure the key is syntactically valid
		if (!preg_match ('/^([a-f0-9]{' . $this->settings['keyLength'] . '})$/', $_GET['key'])) {
			application::sendHeader (404);
			echo "\n<p>The URL specified is not valid. Please check the link given in the e-mail and try again.</p>";
			return false;
		}
		
		# Ensure the key specified represents a file
		if (!isSet ($files[$_GET['key']])) {
			application::sendHeader (404);
			echo "\n<p>The URL specified is not valid. Please check the link given in the e-mail and try again.</p>";
			if ($deleteMode) {
				echo "\n<p>Perhaps you have already deleted it?</p>";
			} else {
				echo "\n<p>" . ($this->settings['onceOnlyInvite'] ? 'Perhaps you have already used it? ' : '') . "Alternatively, it may have timed out. Please check the date given in the e-mail.<br />If it has timed out, ask the sender to " . ($downloadMode ? 're-upload the file' : 'resend the invite') . '.</p>';
			}
			return false;
		}
		
		# Return the validated key
		return $_GET['key'];
	}
	
	
	# Download introduction page
	public function download ($fileTransfer = false)
	{
		# Construct a welcome message
		$welcomeMessage = "\n<p>To collect a file that someone has sent you, please follow the personalised link in the e-mail that you will have been sent.</p>";
		
		# Validate the key or end, showing any error message
		if (!$key = $this->validateKey ($this->currentFiles, $welcomeMessage, true)) {return false;}
		
		# The file exists and so it can be read to the user
		$file = $this->currentFiles[$key];
		
		# In file transfer mode, transfer the file
		if ($fileTransfer) {
			$this->transferFile ($file);
			
		# Otherwise, Show the details
		} else {
			$link = "{$this->baseUrl}/download/{$_GET['key']}/file";
			$html  = "\n<p>The file is now available for you to download.</p>";
			$html .= "<p class=\"download\"><a class=\"actions\" href=\"{$link}\"><img src=\"/images/icons/bullet_go.png\" alt=\"*\" class=\"icon\" /> <strong>Download now</strong></a></p>";
			$html .= "\n<p>Details of the file are as follows:</p>";
			$html .= $this->metadataTable ($file, $link);
			$html .= "\n<p>The file will remain available until the date shown, so you can download it again until then.<br />Or you can <a href=\"{$this->baseUrl}/download/{$_GET['key']}/delete.html\" class=\"delete\" onclick=\"javascript:return confirm('Are you sure you want to delete this file?')\">delete it immediately</a>.</p>";
			echo $html;
		}
	}
	
	
	# File transfer
	public function filedelete ()
	{
		# Validate the key or end, showing any error message
		if (!$key = $this->validateKey ($this->currentFiles, false, false, true)) {return false;}
		
		# Delete the file
		$filename = $this->dataDirectory . $this->currentFiles[$key]['name'];
		unlink ($filename);
		
		# Confirm
		echo "\n<p>The file (" . htmlspecialchars ($this->currentFiles[$key]['file']) . ') has been deleted and cannot be retrieved any further.</p>';
	}
	
	
	# Function to show the file details
	private function metadataTable ($file, $link)
	{
		# Format the size
		if ($file['size'] > (1024 * 1024)) {
			$size = number_format ($file['size'] / (1024 * 1024)) . ' MB';
		} else {
			$size = number_format ($file['size'] / 1024) . ' KB';
		}
		
		# Select details to show
		$details = array (
			'File name' => "<strong><a href=\"{$link}\">{$file['file']}</a></strong>",
			'File size' => $size,
			'Uploaded by' => htmlspecialchars ($file['user']),
			'Uploaded at' => date ('g:ia, jS F Y', $file['timestamp']),
			'Downloadable until' => $file['timeoutFormatted'],
		);
		
		# Compile the HTML
		$html  = application::htmlTableKeyed ($details, array (), true, 'lines', $allowHtml = true);
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to calculate the time the file is available for download until
	private function availableUntil ($timestamp, $formatted = false)
	{
		# Add days onto the upload time
		$result = $timestamp + ($this->settings['days'] * 24 * 60 * 60);
		
		# If formatted, convert to text
		if ($formatted) {
			$result = date ('g:ia, jS F Y', $result);
		}
		
		# Return the result
		return $result;
	}
	
	
	
	# Transfer the file
	private function transferFile ($file)
	{
		# Construct the filename on disk
		$filename = $this->dataDirectory . $file['name'];
		
		# Send headers
		header ('Content-Description: File Transfer');
		header ('Content-Type: application/octet-stream');
		header ('Content-Disposition: attachment; filename=' . $file['file']);
		header ('Content-Transfer-Encoding: binary');
		header ('Expires: 0');
		header ('Cache-Control: must-revalidate, post-check=0, pre-check=0');
		header ('Pragma: public');
		header ('Content-Length: ' . $file['size']);
		ob_clean ();
		ob_end_flush ();	// Also disables output buffering, so that PHP doesn't run out of memory; see: https://www.php.net/readfile#81032
		
		# Read out the file
		readfile ($filename);
		
		# Compile the e-mail
		$message  = "This e-mail confirms that you have uploaded a file with the following details:\n\n";
		$message .= "Filename: {$filename}\n";
		$message .= "To:       {$result['userEmail']}\n";
		$message .= "Note:     " . (substr_count ($result['note'], "\n") ? "\n" : '') . $result['note'] . "\n\n";
		$message .= "The recipient has been sent a unique link to pick up the file, so you need take no further action.\n\n";
		$message .= "\nThey have been informed of the deadline for collection, which is {$availableUntil}.\n\n";
		$message .= "\n--\n";
		$message .= "This message has been sent automatically from the {$this->settings['applicationName']} system run by {$this->settings['institution']}.";
		
		/*
		# Send a receipt to the sender
		$to = $result['userEmail'];
		$subject = "Sendfiles confirmation";
		$message = wordwrap ($message);
		$headers  = "From: {$this->settings['administratorEmail']}>";
		application::utf8Mail ($to, $subject, $message, $headers);
		*/
	}
	
	
	# Invite introduction page
	public function invite ()
	{
		# Construct the HTML
		$html  = "<p>If you are a " . htmlspecialchars ($this->settings['memberDescription']) . " and want to allow someone externally to send you a file, send an invite:";
		$html .= "<p class=\"download\"><a class=\"actions\" href=\"{$this->baseUrl}/invite/send/\"><img src=\"/images/icons/email.png\" alt=\"*\" class=\"icon\" /> <strong>Send an invite</strong></a></p>";
		
		# Show the HTML
		echo $html;
	}
	
	
	# Invite sending page
	public function invitesend ()
	{
		# Start the HTML
		$html  = '';
		
		# Create the form
		$form = new form (array (
			'formCompleteText'	=> false,
			'submitButtonText'		=> 'Send invite',
			'name' => false,
			'displayRestrictions' => false,
		));
		$form->heading ('p', 'Use this form to send someone an invite to let them send you a file. They will be given full instructions.');
		$form->email (array (
			'name'		=> 'email',
			'title'		=> "Recipient's e-mail address",
			'required'	=> true,
		));
		$form->textarea (array (
			'name'		=> 'note',
			'title'		=> "Note (if any)",
			'rows'		=> 3,
		));
		$form->input (array (
			'name'		=> 'name',
			'title'		=> 'Your name',
			'required'	=> true,
			'default'	=> $this->userName,
			'editable'	=> (!$this->userName),
		));
		$form->email (array (
			'name'		=> 'userEmail',
			'title'		=> 'Your e-mail address',
			'required'	=> true,
			'default'	=> $this->userEmail,
			'editable'	=> (!$this->userEmail),
		));
		
		# Obtain the data from a posted form
		if ($result = $form->process ($html)) {
			
			# Generate a random unique key
			$key = $this->generateKey ();
			
			# Determine the filename
			$filename = 'invite.' . $key . '.' . time () . '.' . $this->user;
			$file = $this->invitesDirectory . $filename;
			
			# Obtain the expiry time
			$availableUntil = $this->availableUntil (time (), true);
			
			# Compile the e-mail
			$message  = "{$result['name']} has invited you to send them a file.\n\n";
			$message .= "To send them a file, please go to:\n\n";
			$message .= "  {$_SERVER['_SITE_URL']}{$this->baseUrl}/upload/external/{$key}/\n\n";
			if ($result['note']) {
				$message .= "The following note about this invite was added:\n";
				$message .= "{$result['note']}\n\n";
			}
			$message .= "\nThis invite will be valid until {$availableUntil}.\n\n";
			$message .= "\n--\n";
			$message .= "This message has been sent automatically from the {$this->settings['applicationName']} system run by {$this->settings['institution']}.";
			
			# Send the message
			$to = $result['email'];
			$subject = "Invite for you from {$result['name']} to send a file";
			$message = wordwrap ($message);
			$headers  = "From: {$result['name']} <{$result['userEmail']}>";
			application::utf8Mail ($to, $subject, $message, $headers);
			
			# Display the sent mail
			$html .= "\n<p>{$this->tick} Invite succesfully sent.</p>";
			$html .= "\n<p>An e-mail has been sent to {$to} to give them the details of how to send you a file.</p>";
			$html .= "\n<p>The e-mail explains that they must use the invite by {$availableUntil}.</p>";
			
			# Write the file, inserting the e-mail of the user being invited to do the upload, and the e-mail of the person who sent the invite
			$text = $result['email'] . "\r\n" . $result['userEmail'];
			file_put_contents ($file, $text);
		}
		
		# Show the HTML
		echo $html;
	}
	
	
	# Upload page (internal)
	public function uploadinternal ()
	{
		# Do the upload
		$html = $this->doUpload ($this->user, $this->userName, $this->userEmail);
		
		# Show the HTML
		echo $html;
	}
	
	
	# Upload page (external)
	public function uploadexternal ()
	{
		# If they are a member, point them in the right place
		if ($this->isMember) {
			echo "\n<p>You appear to be a {$this->settings['memberDescription']}.</p>";
			echo "\n<p>Please therefore use the <a href=\"{$this->baseUrl}/upload/internal/\">internal upload page</a>.</p>";
			return;
		}
		
		# Construct a welcome message
		$welcomeMessage  = "\n<p>To send a " . htmlspecialchars ($this->settings['memberDescription']) . " a file, they must first send you an invite.</p>";
		$welcomeMessage .= "\n<p>Please e-mail them, and ask them to send you an invite from the invite page at:<br />{$_SERVER['_SITE_URL']}{$this->baseUrl}/invite/</p>";
		$welcomeMessage .= "\n<p>The invite will contain the personalised link for you to use.</p>";
		
		# Validate the entry key or end, showing any error message
		if (!$key = $this->validateKey ($this->invites, $welcomeMessage, false)) {return false;}
		
		# Determine who the invite was from (and who therefore will be the recipient of the file)
		$file = $this->invitesDirectory . $this->invites[$key]['name'];
		$fileContents = file_get_contents ($file);
		$fileContents = trim ($fileContents);
		
		# Obtain the e-mail of the user being invited to do the upload (which will be used as their 'username'), and the e-mail of the person who sent the invite (whose e-mail will be prefilled when the person does the upload)
		list ($userInvitedToUpload, $userWhoSentInvite) = explode ("\n", $fileContents, 2);
		$userInvitedToUpload = trim ($userInvitedToUpload);
		$userWhoSentInvite = trim ($userWhoSentInvite);
		
		# Set the key to be deleted after successful upload (if required by the settings); otherwise it will get cleaned up naturally
		$deleteInviteFile = ($this->settings['onceOnlyInvite'] ? $file : false);
		
		# Do the upload
		$html  = $this->doUpload ($userInvitedToUpload, false, $userInvitedToUpload, $userWhoSentInvite, $deleteInviteFile);
		
		# Show the HTML
		echo $html;
	}
	
	
	# Function to do the actual uploading
	private function doUpload ($user, $userName, $userEmail, $uploadGoesToEmail = false, $deleteInviteFile = false)
	{
		# Start the HTML
		$html  = "\n<p>Select (below) the file to send to someone.<br />They will then be sent a unique link to this site to enable them to pick it up.</p>";
		$html .= "\n<p>If you have several files to send, zip them up into a single file first.";
		
		# Generate a random unique key to be used for the file
		$key = $this->generateKey ();
		
		# Format the max upload size as a human-readable string
		$uploadMaxFilesize = ini_get ('upload_max_filesize');
		$attachmentsMaxSizeMb = (application::convertSizeToBytes ($uploadMaxFilesize) / (1024 * 1024));
		$attachmentsMaxSize = ($attachmentsMaxSizeMb >= 1000 ? number_format ($attachmentsMaxSizeMb / 1000, 1) . 'GB' : number_format ($attachmentsMaxSizeMb) . 'MB');
		
		# Pre-determine the filename of the file, post-upload, allocating an arbitary username if in external mode
		$appendExtension = '.' . $key . '.' . time () . '.' . $user;
		
		# Validate form elements, because the upload will take place beforehand, resulting in a long wait for problems to be flagged up
		#!# This should be native to ultimateForm
		echo "
		<script src=\"https://code.jquery.com/jquery-latest.min.js\"></script>
		<script type=\"text/javascript\">
			$(document).ready(function(){
				$('#submissionform').submit(function() {
					
					// Validate e-mail field
					var reg = /^([A-Za-z0-9_\-\.\+])+\@([A-Za-z0-9_\-\.])+\.([A-Za-z]{2,4})$/;
					var address = $('#email').val();
					if(reg.test(address) == false){
						alert('Please ensure you have entered a valid e-mail address.');
						$('#email').focus();
						return false;
					}
					
					// Avoid empty description field
					if($('#note').val() == ''){
						alert('You must enter a description.');
						$('#note').focus();
						return false;
					}
					
					// No problems
					return true;
				});
			});
		</script>
		";
		
		# Create the form
		$form = new form (array (
			'formCompleteText'		=> false,
			'submitButtonText'		=> 'Copy over file(s)',
			'name'					=> false,
			'displayRestrictions'	=> false,
			'id'					=> 'submissionform',
		));
		$form->email (array (
			'name'				=> 'email',
			'title'				=> "Recipient's e-mail address",
			'default'			=> ($uploadGoesToEmail ? $uploadGoesToEmail : false),
			'editable'			=> (!$uploadGoesToEmail),
			'required'			=> true,
		));
		$form->upload (array (
			'name'				=> 'file',
			'title'				=> "File to copy over from your computer<br />(max size: {$attachmentsMaxSize})",
			'directory'			=> $this->dataDirectory,
			'appendExtension'	=> $appendExtension,
			'required'			=> true,
			'flatten'			=> true,
			'output'			=> array ('processing' => 'compiled', ),
		));
		$form->textarea (array (
			'name'				=> 'note',
			'title'				=> 'Description of the file',
			'required'			=> true,
			'rows'				=> 3,
		));
		$form->input (array (
			'name'				=> 'name',
			'title'				=> 'Your name',
			'required'			=> true,
			'default'			=> ($userName ? $userName : false),
			'editable'			=> (!$userName),
		));
		$form->email (array (
			'name'				=> 'userEmail',
			'title'				=> 'Your e-mail address',
			'required'			=> true,
			'default'			=> ($userEmail ? $userEmail : false),
			'editable'			=> (!$userEmail),
		));
		
		# Obtain the data from a posted form
		if ($result = $form->process ($html)) {
			
			# Obtain the expiry time
			$availableUntil = $this->availableUntil (time (), true);
			
			# Obtain the originally-uploaded filename (before appendExtension would have been applied)
			#!# May not be giving the correct result
			$filename = false;
			foreach ($result['file'] as $diskFilename => $attributes) {
				$filename = basename ($attributes['tmp_name']);
			}
			
			# Compile the e-mail to the sender
			$message  = "This e-mail confirms that you have uploaded a file with the following details:\n\n";
			$message .= "Filename: {$filename}\n";
			$message .= "To:       {$result['userEmail']}\n";
			$message .= "Note:     " . (substr_count ($result['note'], "\n") ? "\n" : '') . $result['note'] . "\n\n";
			$message .= "The recipient has been sent a unique link to pick up the file, so you need take no further action.\n\n";
			$message .= "\nThey have been informed of the deadline for collection, which is {$availableUntil}.\n\n";
			$message .= "\n--\n";
			$message .= "This message has been sent automatically from the {$this->settings['applicationName']} system run by {$this->settings['institution']}.";
			
			# Send a receipt to the sender
			$to = $result['userEmail'];
			$subject = "Sendfiles confirmation";
			$message = wordwrap ($message);
			$headers  = 'From: ' . $this->settings['administratorEmail'];
			application::utf8Mail ($to, $subject, $message, $headers);
			
			# Compile the e-mail to the recipient
			$message  = "{$result['name']} has left a file for you to pick up.\n\n";
			$message .= "To pick up the file, please go to:\n\n";
			$message .= "  {$_SERVER['_SITE_URL']}{$this->baseUrl}/download/{$key}/\n\n";
			$message .= "The following note about this file was added:\n";
			$message .= "{$result['note']}\n\n";
			$message .= "\nDeadline for collection:  {$availableUntil}.\n\n";
			$message .= "\n--\n";
			$message .= "This message has been sent automatically from the {$this->settings['applicationName']} system run by {$this->settings['institution']}.";
			
			# Send the message to the recipient
			$to = $result['email'];
			$subject = "File for you from {$result['name']}";
			$message = wordwrap ($message);
			$headers  = "From: {$result['name']} <{$result['userEmail']}>";
			application::utf8Mail ($to, $subject, $message, $headers);
			
			# Display a confirmation message, replacing all the HTML so far generated
			$html  = "\n<p>{$this->tick} File succesfully uploaded.</p>";
			$html .= "\n<p>An e-mail has been sent to {$to} informing them how to pick up the file. An e-mail has also been sent to you as confirmation.</p>";
			$html .= "\n<p>The e-mail explains that they must pick it up by {$availableUntil}.</p>";
			if (!$userName) {
				if ($this->settings['onceOnlyInvite']) {
					$html .= "\n<p>You can delete the invite e-mail now, as the link will not work again.</p>";
				} else {
					$html .= "\n<p>You can send further files, using the same link in the invite e-mail, if you need to.</p>";
				}
			}
			
			# Delete the invite file if required
			if ($deleteInviteFile) {
				unlink ($deleteInviteFile);
			}
		}
		
		# Return the HTML
		return $html;
	}
	
	
	# Function to generate a random and unique key
	private function generateKey ()
	{
		# Loop until a unique key is found (in practice, this will happen the first time)
		while (true) {
			$key = application::generatePassword ($this->settings['keyLength'], $numeric = false);
			
			# If the key does not already exist, return it
			if (!array_key_exists ($key, $this->currentFiles)) {
				return $key;
			}
		}
	}
}

?>
