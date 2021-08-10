<!DOCCTYPE html>

<?php 
session_start();
?>

<script>
    function unlock(){
        lock.disabled = true;
    }
    //returns true if every text box has text in it
    function checkIfAllFilled() {
        var x = document.getElementsByClassName("required_text");
        var i;
        for(i = 0; i < x.length; i++){
            if(x[i].textLength == 0){
                return false;
            }
        }
        return true;
    }
</script>

<head>
<meta http-equiv="content-type" content="text/html; charset=utf-8" />
<link href="https://newjimi.cce.oregonstate.edu/concept_warehouse/default.css" rel="stylesheet" type="text/css" />
<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1" />

<!-- -->
<link rel="stylesheet" href="style.css">
<!-- -->

</head>

<body>
<div style='margin-left:20px;'>
<br>

<?php
// The following line controls PHP diagnostic messages. 0 = No messages,  1 = Add messages to output
ini_set('display_errors', 1);

//some necessary and semi-essential global variables
$standalone_mode = 1;  // 1 = for use in standalone web page;  0 = for use when integrated in CW
$standalone_version = "v01";  // Should be updated whenever code is updated & published
$student_id = 999999; 
$saved_test_itassociation_id = 888888;
$image_URL = "";
$sim_URL = "";
$mod_name = "";

//parse the XML
$XML = parse("1-1.xml");

$mod_name= (string)$XML->module_name;

//What session was the user on last? Did they just start ("START-SKIP")?
$section = setup($XML);

if(isset($_POST["purge"])){
    echo("purging");
    $_SESSION = [];
    $section = "START-SKIP";
    unset($_POST["purge"]);
}


//if there was a section last and it was unlocked
if($section != "START-SKIP" && !locked((string)$XML->module_name, $section)){
    //get it
    $section_object = getSection($XML, $section);
    //save whatever data we can, and lock it
    saveAnswers($section_object);
    applySkips($section_object);
    lockPage($XML->module_name, $section);
}

echo("[");
echo($section);
echo("--becomes->");
//what section were we on last time is turned into the section we are on now
$section = getNextSection($XML, $section);

//if done, print out done
if($section == "DONE"){
    echo("DONE");
    endProgram();
} else if(locked((string)$XML->module_name, (string)$section)){ // if the section is locked, display that it is locked
    echo buildPage($XML, $section, 1);
} else {
    //otherwise, just display the page
    echo buildPage($XML, $section, 0);
}

exit;

function locked($mod_name, $section_id){
    return isset($_SESSION[$mod_name . "locked"][$section_id]) && $_SESSION[$mod_name . "locked"][$section_id] == 1;
}

//given an ID, lock that section from future changes
function lockPage($mod_name, $section){
    $_SESSION[$mod_name . "locked"][$section] = 1;
    echo("locked " . $section);
}

//what session do we have to start? 
//"START" means that they are just starting. Anything else is the previous section
function setup($XML){
    global $student_id;
    global $saved_test_itassociation_id;
    global $standalone_mode;
    global $standalone_version;
    global $sim_URL;
    global $image_URL;
    global $mod_name;

    $CW_rev_num = $XML->version;

	// Suppress standard CW IT Submit button; not needed for this exercise
	$_SESSION['omit_submit_button'] = true;

    $sim_url = "https://newjimi.cce.oregonstate.edu/concept_warehouse/";
	if ($standalone_mode) {
		$image_url = "";
	} else {
		$image_url = "https://newjimi.cce.oregonstate.edu/concept_warehouse/test/pendulum_IT/";
	}
    
    //get student ID and such
    if ($standalone_mode) {
		echo("<title>".$mod_name." (Standalone $standalone_version)</title>");
	} else {
		echo("<title><b>".$mod_name."</b> (v$CW_rev_num)<br></title>");
		$student_id = $_SESSION['db_pointer_kludge']->GetStudentId_PrimaryToken(md5($_SESSION['lastname']));
		$saved_test_itassociation_id = $_SESSION['current_saved_test_itassociation_id'];
	}

    //if there is an answer that has been set
    if (isset($_POST['ans_submit']) || isset($_POST['purge'])) {
		$section = isset($_POST['case_num']) ? $_POST['case_num'] : NULL;
        return $section;
		
	} else { // student has started a new session; figure out if they are just starting, or continuing a prev session
		$last_case = GetME_IBLALastCase($student_id, $saved_test_itassociation_id);
		if ($last_case < 0) { // no record found for this student, so start one
			AddME_IBLAInitialRecord($student_id, $saved_test_itassociation_id, $CW_rev_num);
			$last_case = -1;
		}
		$section = getSectionFromIndex($XML, $last_case);
        //allows resetting in standalone
        if(0){
            unset($_SESSION[$mod_name]);  // clear all previous student-answer info in SESSION state
            unset($_SESSION[$mod_name."locked"]);  // clear all previous student-answer info in SESSION state
        }

	}
    return $section;
}

// gets a section ID from an index. Gross!
function getSectionFromIndex($XML, $index){
    if($index == -1){
        return "START-SKIP";
    }
    return $XML->section[$index]["id"];
}

//parse the XML file
function parse($XMLdoc){
    $xml = simplexml_load_file($XMLdoc) or die("Error: Cannot create object");
    //check for errors
    if ($xml === false) {
        echo "Failed loading XML: ";
        foreach(libxml_get_errors() as $error) {
          echo "<br>", $error->message;
        }
    }
    return $xml;
}

//saves the answers to the server
function saveAnswers($section_object){
    global $student_id; 
    global $saved_test_itassociation_id;
    $data_array = array();
    $i = 0;
    foreach($section_object as $question){
        if($question->getName() == "multiple_choice"){
            if(isset($_POST[(string)$question["id"]])){
                $data_array[$i] = $_POST[(string)$question["id"]];
            } else {
                $data_array[$i] = "[No answer selected]";
            }
            $data_array[$i + 1] = $_POST[(string)$question["id"]."w"];
            $i = $i + 2;
        } else if($question->getName() == "open_responce") {
            $data_array[$i] = $_POST[(string)$question["id"]."w"];
            $i = $i + 1;
        } else if($question->getName() == "multiple_selection"){
            if(isset($_POST[(string)$question["id"]])){
                $answers = $_POST[(string)$question["id"]];
                foreach($answers as $answer){
                    $data_array[$i] = $answer;
                    $i = $i + 1;
                }
            } else {
                $data_array[$i] = "[No answer selected]";
                $i = $i + 1;
            }
            
            $data_array[$i] = $_POST[(string)$question["id"]."w"];
            $i = $i + 1;
        }
    }

    UpdateME_IBLARecord($student_id, $saved_test_itassociation_id, $section_object["id"], $data_array);
}

//grades a session object that has just been posted. Applies skips.
function applySkips($section_object){
    $prog = $section_object->progression_plan;
        //for each skip
        foreach($prog->skip as $skip){
            switch($skip["type"]){
                case "all": // if every question is correct, skip the specified question
                    if(allCorrect($section_object)){
                        //mark the skipped section as "to skip"
                        markSkip((string)$skip->skips);
                    }
                    break;
                case "some": // if some questions (which are listed) are correct, skip the specified question
                    if(correctInList($section_object, $skip->question)){
                        //mark the skipped section as "to skip"
                        markSkip((string)$skip->skips);
                    }
                    break;
                case "number": // there exists a number of correct questions. If that number is met, skip.
                    if(numberCorrect($section_object, (int)$skip->number)){
                        //mark the skipped section as "to skip"
                        markSkip((string)$skip->skips);
                    }
                    break;
                default:
                    echo("PP not given type in XMl!");
            }
        }
}

//given a section, finds the stored answers. It then determines the next section and returns it
function getNextSection($XML, $section){
    $section_object = getSection($XML, $section);
    
    //if you are on the first section, there is nothing to grade!
    if($section == "START-SKIP"){
        return "START";
    }
    
    //then advance! Ignore sections that are set to be skipped.
    $section = (string)$section_object->progression_plan->next;
    $section_object = getSection($XML, $section);
    while(strcmp($section, "DONE") != 0 && skippable($section_object)){
        $section = (string)$section_object->progression_plan->next;
        if($section == null){
            $section = $section_object->autopass;
        }
        $section_object = getSection($XML, $section);
    }
    return (string) $section;
}

function skippable($section_object){
    global $mod_name;
    if(isset($_SESSION[$mod_name][(string)$section_object["id"]])){
        $needed_to_skip = 1;
        if($section_object->progression_plan->required_to_skip != 0){
            $needed_to_skip = (int)$section_object->progression_plan->required_to_skip;
        }
        return $_SESSION[$mod_name][(string)$section_object["id"]] >= $needed_to_skip;
    } else {
        return false;
    }
}

//marks a section for skipping
function markSkip($section_id){
    global $mod_name;
    if(isset($_SESSION[$mod_name][$section_id])){
        $_SESSION[$mod_name][$section_id] += 1;
    } else {
        $_SESSION[$mod_name][$section_id] = 1;
    }
}

//returns true if every question has the correct answer posted
function allCorrect($section_object){
    foreach($section_object as $question){
        //if the posted answer and the expected answer do not match
        if(answerable($question->getName()) && !correct($section_object, $question)){
            return false;
        }
    }
    return true;
}

//is every question in list $questions answered correctly?
function correctInList($section_object, $questions){
    foreach($questions as $question){
        //if the posted answer and the expected answer do not match
        if(answerable($question->getName()) && !correct($section_object, $question)){
            return false;
        }
    }
    return true;
}

//is the number of correct questions greater than or equal to the given number?
function numberCorrect($section_object, $number){
    $correct = 0;
    foreach($section_object as $question){
        //if the posted answer and the expected answer match
        if(answerable($question->getName()) && correct($section_object, $question)){
            $correct += 1;
        }
        if($correct >= $number){
            echo($correct . " correct <br>");
            return true;
        }
    }
    return false;
}

function correct($section_object, $question){
    if(!isset($_POST[(string)$question["id"]])){
        return false;
    }
    return strcmp($_POST[(string)$question["id"]], $question->responce->correct_answer) == 0;
}

//is a question answerable?
function answerable($type){
    return $type == "multiple_choice" || $type == "multiple_selection";
}

function buildPage($XML, $section, $submitted){
    global $standalone_mode, $standalone_version;

    $spaces5 = "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
    echo($section);
    echo("]");

    //setup the post
	$html_to_return = '<form name="me_sims" method="POST">';
    $html_to_return.= '<input type="hidden" name="case_num" value="' . $section . '">';

    if($submitted){
        $html_to_return.= "<b>This page has already been submitted. Your answers cannot be changed now.</b><br>";
    }

    //the XML for the section
    $section_XML = getSection($XML, $section);
    //load each of the questions (And check if there is an autopass, which unlocks the submit button)
    $unlocked = 1;
    $block_count = 0;
    foreach($section_XML->children() as $number => $question){
        /*lock the question if it needs locking*/
        if($question->getName() == "multiple_choice"){
            $unlocked = 0;
        }
        if($question->getName() == "open_responce"){
            $unlocked = 0;
        }
        if($question->getName() == "multiple_selection"){
            $unlocked = 0;
        }

        /*build the question*/
        if($submitted){
            $question_text = buildLockedQuestion($number, $question);
        } else {
            $question_text = buildQuestion($number, $question);
        }

        /*encase it in a block, if it needs to be.*/
        if($block_count > 0){
            $html_to_return .= blockBuilder($question_text);
            $block_count -= 1;
            if($block_count == 0){
                $html_to_return.= endBlock();
            }
        } else {
            $html_to_return .= $question_text;
        }

        /*make a block, if that is what is necessary*/
        if($question->getName() == "side_by_side"){
            $block_count = intval($question->number_of_elements);
            $html_to_return.= startBlock();
        }
    }

    $html_to_return .= "<div class='ender'>";

    if($submitted){
        $html_to_return.= '<input type="submit" name="purge" value="reset" />';
        $unlocked = 1;
    } else {
        $html_to_return.= "<br>You must answer the questions above to submit.<br>";
    }
    if($unlocked){
        $html_to_return.= '<input type="submit" name="ans_submit" value="Submit" id="lock">';
    } else {
        $html_to_return.= '<input type="submit" name="ans_submit" value="Submit" id="lock" disabled>';
    }

    $html_to_return .= "</div>";

    return $html_to_return;
}

function buildQuestion($number, $question){
    switch($question->getName()){
        case "text":
            return putText($question);
        case "multiple_choice":
            return putMultipleChoice($question);
        case "image":
            return putImage($question);
        case "video":
            return putVideo($question);
        case "open_responce":
            return putOpenResponce($question);        
        case "title":
            return putTitle($question);
        case "multiple_selection":
            return putMultipleSelection($question);
        case "simulation":
            return putSimulation($question);
        case "table":
            return putTable($question);
    }
    return "";
}

function buildLockedQuestion($number, $question){
    switch($question->getName()){
        case "multiple_choice":
            return "MC";
        case "open_responce":
            return "OR";
        case "multiple_selection":
            return "MS";
    }
    return buildQuestion($number, $question);
}

function putSimulation($question){
    //we are going to use an iframe. Much easier than rebuilding the entire simuation...
    return '<div class="simulation"> 
    <iframe src="'.(string)$question->location.'/index.html" height="300" width="480" style="border:none;" scrolling="no" title="test">
    </iframe> </div>';
}

//returns out a multiple choice question
function putMultipleChoice($question){
    $spaces5 = "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
    $output = "<div class='multiple_choice'>";
    if($question->question_title != NULL){
        $output .= $question->question_title."<br>";
    }
    $output .= $question->question_text."<br>";
    foreach ($question->responce->option as $option) {
        $output .= $spaces5 . '<input type="radio" name="'.$question["id"].'" value="' . $option . '"> ' . $option . "<br>";
    }
    $output.= "Explain your reasoning.<br>";
    $output.= '<textarea type="text" class="required_text" name="' . $question["id"] . 'w' . '" id="' . $question["id"] . 'w' . '" rows=3 cols=65
        onKeyUp="if(checkIfAllFilled()) {lock.disabled = false} else {lock.disabled = true}"></textarea><br>';
    $output .= "<br></div>";
    return $output;
}

//returns out a multiple choice question
function putMultipleSelection($question){
    $spaces5 = "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
    $output = "<div class='multiple_selection'>";
    if($question->question_title != NULL){
        $output .= $question->question_title."<br>";
    }
    $output .= $question->question_text."<br>";

    foreach ($question->responce->option as $option) {
        $output .= $spaces5 . '<input type="checkbox" name="'.$question["id"].'[]" value="' . $option . '"> ' . $option . "<br>";
    }
    $output.= "<br>Explain your reasoning.<br>";
    $output.= '<textarea type="text" class="required_text" name="' . $question["id"] . 'w' . '" id="' . $question["id"] . 'w' . '" rows=3 cols=65
        onKeyUp="if(checkIfAllFilled()) {lock.disabled = false} else {lock.disabled = true}"></textarea><br><br>';
    $output .= "<br></div>";
    return $output;
}

function putTitle($question){
    return "<div class='title'><br><h1>".$question."</h1><br></div>";
}

function putOpenResponce($question){
    $output = "<div class='open_responce'>";
    if($question->question_title != NULL){
        $output .= $question->question_title."<br>";
    }
    if($question->question_text != NULL){
        $output .= $question->question_text."<br>";
    }
    $output.= '<textarea type="text" class="required_text" name="' . $question["id"] . 'w' . '" id="' . $question["id"] . 'w' . '" rows=3 cols=65
        onKeyUp="if(checkIfAllFilled()) {lock.disabled = false} else {lock.disabled = true}"></textarea><br>';
    $output .= "</div>";
    return $output;
}

//returns out an image section
function putImage($question){
    global $image_URL; 
    $output = '<div class="image"><br><img src="' . $image_URL . (string)$question->URL . '"><br>';
    if($question->caption != NULL){
        $output .= "<div class='subtitle'>$question->caption</div><br>";
    }
    $output .= "<br></div>";
    return $output;
}

//returns out a video section
function putVideo($question){
    $output = "<div class='video'>";
    $width = intval($question->width);
    $height = intval($question->height);
    $output .= '<iframe class="video" width='.$width.' height='.$height.' src="'.$question->URL.'" allowfullscreen></iframe>';
    if($question->caption != NULL){
        $output .= "<br><div class='subtitle'>$question->caption</div><br><br>";
    }
    $output .= "</div>";
    return $output;
}

//returns out at table
function putTable($table){
    $output = "<div class='table'><br><table>";
    foreach($table as $row){
        $output .= "<tr>";
        foreach($row as $element){
            $output .= "<th>";
            $output .= parseText((string)$element);
            $output .= "</th>";
        }    
        $output .= "</tr>";
    }
    $output .= "</table><br></div>";
    return $output;
}

//returns out a text section
function putText($text){
    return "<div class='text'><p>".parseText($text)."</p></div>";
}

//returns out a block header
function startBlock(){
    return "<br><ul class='divider'>";
    //return "<div class='block'>";
}

//returns a block container
function blockBuilder($text){
    return "<li>".$text."</li>";
    //return "<div class='block-child'>".$text."</div>";
}

//ends a block
function endBlock(){
    return "</ul>";
    //return "</div>";
}

function getSection($XML, $section){
    foreach($XML->children() as $sections){
        if(strcmp($sections['id'], $section) == 0){
            return $sections;
        }
    }
    return NULL;
}

function endProgram(){
    global $standalone_mode;
    $html_to_return = "<br><b><u>Thank You</u></b><br><br>";
    $html_to_return.= "You have completed the activity.<br><br>";

    if ($standalone_mode) {
        $on_click = "document.location=document.location;"; // restart the Activity
    } else {
        $on_click = "document.location='CW.php?goto=student_home';"; // return to the Student Home page
    }
    // $html_to_return .= '<br><br><br><input type="submit" name="done" value="Done" onClick="winref.close(); window.location=CW.php?goto=student_home;">';
    $html_to_return.= '<input type="button" name="done" value="Done" onClick="' . $on_click . '">';
    $html_to_return.= "<br><br><br><br>";
    echo($html_to_return);
}

function UpdateME_IBLARecord($student_id, $saved_test_itassociation_id, $section, $data_array){
    echo("storing[");
    foreach($data_array as $index => $answer){
        echo("(".$index.",".$answer.")");
    }
    echo("]<br>");
    return -1;
}

function parseText($input){
    //find any underscore followed by at least one alphanumeric
    //it will become a <sub> section
    $pattern = "/_[abcdefghijklmnopqrstuvwxyz1234567890]+/i";
    $matches;
    preg_match_all(
        $pattern,
        $input,
        $matches
    );
    foreach($matches[0] as $match){
        $input = str_replace($match,"<sub>".substr($match,1)."</sub>",$input);
    }

    //catch newlines
    $input = str_replace('\n',"<br>",$input);

    return $input;
}

function setJumpTo(){
    $html_to_return = "";
    // Set up "jump to" selector to allow user to skip to chosen case.
	// This feature will be included in the production CW version.  Assuming that students
	// will not guess the GET param that enables it.
	if (isset($_GET['show_jump_to'])) {
		if (isset($_POST['jump'])) { // user has clicked the jump-to Go button
			$selected_case = $_POST['jump_to'];
		} elseif (isset($_POST['case_num'])) { // User has clicked a Submit button
			// $selected_case = $_POST['case_num'] + 1;
			$selected_case = NextSection($_POST['case_num']);
		} else { // User has started a new session
			$selected_case = 0;
		}
		// $html_to_return .= "<script type='text/javascript'>function autoSubmit(f){f.submit();}</script>";
		$html_to_return.= "Jump to section: ";
		// $html_to_return .= '<select name="jump_to" id="jump_to" onChange="autoSubmit(this.form);">';
		$html_to_return.= '<select name="jump_to" id="jump_to">';
		for ($i = 0; $i <= 20; $i++) {
			if ($i == $selected_case) {
				$html_to_return.= "<option value=$i SELECTED>$i</option>";
			} else {
				$html_to_return.= "<option value=$i>$i</option>";
			}
		}
		$html_to_return.= "</select>$spaces5";
		$html_to_return.= '<button name="jump" type="submit"> Go </button><br><br>';
	}
    return $html_to_return;
}

// Dummy db-interaction functions for use with standalone version
function GetME_IBLALastCase($student_id, $saved_test_itassociation_id) {
    return -1;
}
function AddME_IBLAInitialRecord($student_id, $saved_test_itassociation_id, $CW_rev_num) {
    return 0;
}
function CompleteME_IBLARecord($student_id, $saved_test_itassociation_id, $last_case) {
    return 0;
}

?>
</div>
</body>