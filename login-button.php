<?php 

// Provides a range of font sizes for use within the form options
function fontsize()
{
    $number_range = array();
    $thru100 = range(0, 100);
    echo '<option value="">Please Select</option>';
    foreach ($thru100 as $number) 
    {
        echo '<option value="'.$number.'px">' . $number . 'px</option>';
    }
}

?>

<script type="text/javascript">
    // Responsible for the setup of all the form options
    jQuery(document).ready(function() {

        jQuery( "#pp-lf-tabs" ).tabs();

        jQuery('.pp-lf-default').click(function()
        {
            tinyMCE.execCommand("mceInsertContent", false, "[login_page]");
            jQuery(".pp-lf-form").hide();
        });

        jQuery('.pp-lf-advanced').click(function()
        {
            jQuery('.pp-lf-defaultoptions, .pp-lf-default, .pp-lf-advanced').hide();
            jQuery('.pp-lf-advancedoptions, #pp-lf-submit').show();
        });

        // Slider pixel size
        var pixelslider = ['width', 'height'];
        jQuery.each(pixelslider, function(index, value) 
        {
            var $psvalue = jQuery("#pp-lf-"+value);
            var $psvalueslider = jQuery("#pp-lf-"+value+"slider");
            jQuery("#pp-lf-"+value+"slider").slider({
                value: 320,
                min: 200,
                max: 1000,
                step: 10,
                slide: function(event, ui)
                {
                    $psvalue.val( ui.value + 'px');
                }
            });
            $psvalue.val( $psvalueslider.slider( "value" ) + 'px' );
        });

        // Color Picker
        var colorpicker = ['bgcolor', 'textcolor', 'headertextfontcolor', 'inputcolor', 'inputtextcolor', 'inputbordercolor', 'supportingtextfontcolor', 'buttonbgcolor', 'buttontextcolor', 'buttonbordercolor', 'buttonhovertextcolor', 'buttonhoverbgcolor', 'buttonhoverbordercolor'];
        jQuery.each(colorpicker, function(index, value) 
        {
            var $cpvalue = jQuery('#pp-lf-'+value);
            $cpvalue.iris({
                width: 200,
                hide: false
            });
            $cpvalue.iris({ change: function(event, ui)
                {
                    var colorpickervar = jQuery('#pp-lf-'+value).val();
                    $cpvalue.siblings('.iris-border').css('background-color', colorpickervar);
                }
            });
        });
    });
</script>

<style type="text/css">
li a
{
    outline-color: transparent;
}
#pp-lf-admin
{
    display: inline-block;
    width: 100%;
}
#pp-lf-tabs .ui-widget-header
{
    border: none!important;
}
#pp-lf-tabs .tablinks li a
{
    padding: 10px 15px!important;
    outline: none!important;
}
#pp-lf-tabs.ui-tabs .ui-tabs-nav li
{
    background: none!important;
}
#pp-lf-tabs
{
    display: inline-block;
    width: 100%;
    background-color: white;
    background-image: none!important;
    border: 1px solid lightgrey;
    padding: 15px;
    box-sizing: border-box;
    -webkit-box-sizing: border-box;
    -moz-box-sizing: border-box;
}
.tablinks
{
    background-color: white!important;
    background-image: none!important;
}
.tablinks li
{
    border: none!important;
}
.tablinks li a
{
    margin: 2px!important;
    padding: 1em 2em!important;
    border: none!important;
    border-radius: 4px!important;
    background-color: #1c94c4!important;
    color: white!important;
}
.tablinks li a:hover
{
    border: none!important;
    background-color: white!important;
    color: #1c94c4!important;
    cursor: pointer!important;
    transition: background-color 0.5s ease, color 0.5s ease;
    -moz-transition: background-color 0.5s ease, color 0.5s ease;
    -webkit-transition: background-color 0.5s ease, color 0.5s ease;
}
.tablinks li.ui-state-active a
{
    background-color: #10293f!important;
}
#pp-lf-overlay 
{
    position: fixed; 
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: #000;
    opacity: 0.5;
    filter: alpha(opacity=50);
    z-index: 10000;
}
.pp-lf-form-container 
{
    position: absolute;
    z-index: 10001;
    margin: 50px 0px 50px -22.5%;
    left: 50%;
    width: 45%;
}

.pp-lf-inner-wrapper 
{
    border-radius: 8px;
    border: 8px solid white;
    background: #fff;
    background-image: url('https://ontraport.com/wp-content/themes/ontraport/images/bg_cream_y-only.png');
    padding: 25px;
    z-index: 103;
    box-sizing: border-box;
    -moz-box-sizing: border-box;
    -webkit-box-sizing: border-box;
}
.pp-lf-form-title 
{
    font-size: 20pt;
    margin-bottom: 10px;
    font-family: gothamboldregular, Helvetica, Arial, sans-serif;
    font-weight: bold;
}
.pp-lf-form-text 
{
    margin-bottom: 20px;
}
.pp-lf-form-row 
{
    margin-bottom: 10px;
    display: inline-block;
    width: 100%!important;
    padding: 15px 0px;
    box-sizing: border-box;
    -moz-box-sizing: border-box;
    -webkit-box-sizing: border-box;
    float: left;
}
.pp-lf-form-item 
{
    display: inline-block;
    width: 50%;
    min-width: 200px;
    padding-right: 3%;
    box-sizing: border-box;
    -moz-box-sizing: border-box;
    -webkit-box-sizing: border-box;
    float: left;
}
.pp-lf-form-row label
{
    display: inline-block;
    width: 100%;
    font-size: 10pt;
    font-weight: bold;
    box-sizing: border-box;
    -moz-box-sizing: border-box;
    -webkit-box-sizing: border-box;
}
.pp-lf-form-row input
{
    display: inline-block;
    width: 99%;
    padding: 7px;
    font-size: 10pt;
    border: 1px solid lightgray!important;
    box-sizing: border-box!important;
    -moz-box-sizing: border-box!important;
    -webkit-box-sizing: border-box!important;
}
.pp-lf-form-row select
{
    display: inline-block;
    width: 100%;
    height: 32px;
    font-size: 10pt;
    box-sizing: border-box;
    -moz-box-sizing: border-box;
    -webkit-box-sizing: border-box;
}
#pp-lf-submit
{
    display: inline-block;
    width: 100%;
    margin: 20px auto 0px;
    padding: 15px;
    font-size: 14pt;
    background-color: #91B961;
    background-image: none;
    border: 1px solid #719742;
    color: white;
    border-radius: 6px;
}
#pp-lf-submit:hover
{
    background-color: #719742;
    color: white;
    transition: background-color 0.5s ease, color 0.5s ease;
    -moz-transition: background-color 0.5s ease, color 0.5s ease;
    -webkit-transition: background-color 0.5s ease, color 0.5s ease;
    cursor: pointer;
}
.twothirds
{
    width: 70%;
    float: left;
    display: inline-block;
    box-sizing: border-box;
    -moz-box-sizing: border-box;
    -webkit-box-sizing: border-box;
}
.onethird
{
    width: 25% !important;
    display: inline-block;
    box-sizing: border-box;
    -moz-box-sizing: border-box;
    -webkit-box-sizing: border-box;
    margin-left: 4%;
}
.ui-slider
{
    margin-top: 11px;
}
.ui-tabs .ui-tabs-panel
{
    padding: 4px!important;
}
.pp-lf-option
{
    cursor: pointer;
    display: inline-block;
    width: 48%;
    margin: 1%;
    padding: 15px;
    font-size: 11.5pt;
    float: left;
    background-color: white;
    text-align: center;
    color: #1c94c4!important;
    border-radius: 5px;
    box-sizing: border-box;
    -moz-box-sizing: border-box;
    -webkit-box-sizing: border-box;
}
.pp-lf-option:hover
{
    background-color: #1c94c4!important;
    color: white!important;
    transition: background-color 0.5s ease, color 0.5s ease;
    -moz-transition: background-color 0.5s ease, color 0.5s ease;
    -webkit-transition: background-color 0.5s ease, color 0.5s ease;
}
.pp-lf-columns
{
    display: inline-block;
    padding: 10px;
    -moz-box-sizing: border-box;
    -webkit-box-sizing: border-box;
    box-sizing: border-box;
    float: left;
}
</style>

<div class="pp-lf-form" style="display: none;">
    <div id="pp-lf-overlay"></div>
    <div class="pp-lf-form-container">
        <div class="pp-lf-inner-wrapper">
            <div class="pp-lf-form-title">Add a login form!</div>
            <div class="pp-lf-form-text">Leave options blank if you would prefer to use the defaults.</div>
            <div id="pp-lf-admin">
                <div class="pp-lf-default pp-lf-option">
                    Use the default style
                </div>
                <div class="pp-lf-advanced pp-lf-option">
                    Customize it!
                </div>
                <div class="pp-lf-advancedoptions" style="display: none;">
                    <div id="pp-lf-tabs">
                        <ul class="tablinks">
                            <li><a href="#pp-lf-tabs-1">General</a></li>
                            <li><a href="#pp-lf-tabs-2">Text</a></li>
                            <li><a href="#pp-lf-tabs-3">Text Style</a></li>
                            <li><a href="#pp-lf-tabs-4">Form Style</a></li>
                            <li><a href="#pp-lf-tabs-5">Button Style</a></li>
                            <li><a href="#pp-lf-tabs-6">Button Hover</a></li>
                        </ul>
                        <div class="generaloptions" id="pp-lf-tabs-1">
                            <div class="pp-lf-form-row">
                                <div class="pp-lf-form-item">
                                    <label>Form Style</label>
                                    <select name="formalign" id="pp-lf-style">
                                        <option>Please Select</option>
                                        <option value="default">Default</option>
                                        <option value="fullwidth">Full Width</option>
                                    </select> 
                                </div>
                                <div class="pp-lf-form-item">
                                    <label>Forgotten Password</label>
                                    <select name="formalign" id="pp-lf-forgotpw">
                                        <option>Please Select</option>
                                        <option value="true">On</option>
                                        <option value="false">Off</option>
                                    </select> 
                                </div>
                            </div>
                            <div class="pp-lf-form-row widthalign">
                                <div class="pp-lf-form-item">
                                    <label>Form Width</label>
                                    <div name="width" data-type="slider" id="pp-lf-widthslider" class="twothirds slider"></div>
                                    <input type="text" id="pp-lf-width" style="border: 0; color: #5ba0d0; font-weight: bold;" class="onethird" />
                                </div>
                                <div class="pp-lf-form-item">
                                    <label>Form Alignment</label>
                                    <select name="formalign" id="pp-lf-formalign">
                                        <option>Please Select</option>
                                        <option value="left">Left</option>
                                        <option value="center">Center</option>
                                        <option value="right">Right</option>
                                    </select>  
                                </div> 
                            </div>
                            <div class="pp-lf-form-row">
                                <div class="pp-lf-form-item">
                                    <label>Background Color</label>
                                    <div name="bgcolor" data-type="slider" id="pp-lf-bgcolorpicker" class="colorpicker"></div>
                                    <input type="text" id="pp-lf-bgcolor" style="border: 0; color: #5ba0d0; font-weight: bold;" value="#ffffff" data-default-color="#ffffff" />
                                </div>
                                <div class="pp-lf-form-item">
                                    <label>Text Color</label>
                                    <div name="textcolor" data-type="slider" id="pp-lf-textcolorpicker" class="colorpicker"></div>
                                    <input type="text" id="pp-lf-textcolor" style="border: 0; color: #5ba0d0; font-weight: bold;" value="#333333" data-default-color="#333333" />
                                </div>
                            </div>
                        </div>
                        <div class="textoptions" id="pp-lf-tabs-2">
                            <div class="pp-lf-form-row">
                                <div class="pp-lf-form-item">
                                    <label>Header Text</label>
                                    <input type="text" id="pp-lf-headertext" class="pp-lf-textinput" style="border: 0; color: #5ba0d0; font-weight: bold;" />
                                </div>
                                <div class="pp-lf-form-item">
                                    <label>Supporting Text</label>
                                    <input type="text" id="pp-lf-supportingtext" class="pp-lf-textinput" style="border: 0; color: #5ba0d0; font-weight: bold;" />
                                </div>
                            </div>
                            <div class="pp-lf-form-row">
                                <div class="pp-lf-form-item">
                                    <label>Username Text</label>
                                    <input type="text" id="pp-lf-usernametext" class="pp-lf-textinput" style="border: 0; color: #5ba0d0; font-weight: bold;" />
                                </div>
                                <div class="pp-lf-form-item">
                                    <label>Password Text</label>
                                    <input type="text" id="pp-lf-passwordtext" class="pp-lf-textinput" style="border: 0; color: #5ba0d0; font-weight: bold;" />
                                </div>
                            </div>
                            <div class="pp-lf-form-row">
                                <div class="pp-lf-form-item">
                                    <label>Remember Me Text</label>
                                    <input type="text" id="pp-lf-remembertext" class="pp-lf-textinput" style="border: 0; color: #5ba0d0; font-weight: bold;" />
                                </div>
                                <div class="pp-lf-form-item">
                                    <label>Button Text</label>
                                    <input type="text" id="pp-lf-buttontext" class="pp-lf-textinput" style="border: 0; color: #5ba0d0; font-weight: bold;" />
                                </div>
                            </div>
                        </div>
                        <div class="stylingoptions" id="pp-lf-tabs-3">
                            <div class="pp-lf-form-row">
                                <div class="pp-lf-form-item">
                                    <label>Header Text Alignment</label>
                                    <select name="formalign" id="pp-lf-headertextalignment">
                                        <option value=''>Please Select</option>
                                        <option value="left">Left</option>
                                        <option value="center">Center</option>
                                        <option value="right">Right</option>
                                    </select>  
                                </div>
                                <div class="pp-lf-form-item">
                                    <label>Supporting Text Alignment</label>
                                    <select name="formalign" id="pp-lf-supportingtextalignment">
                                        <option value="">Please Select</option>
                                        <option value="left">Left</option>
                                        <option value="center">Center</option>
                                        <option value="right">Right</option>
                                    </select>  
                                </div>
                            </div> 
                            <div class="pp-lf-form-row">
                                <div class="pp-lf-form-item">
                                    <label>Header Text Font</label>
                                    <input type="text" id="pp-lf-headertextfont" class="pp-lf-textinput" style="border: 0; color: #5ba0d0; font-weight: bold;" />
                                </div>
                                <div class="pp-lf-form-item">
                                    <label>Supporting Text Font</label>
                                    <input type="text" id="pp-lf-supportingtextfont" class="pp-lf-textinput" style="border: 0; color: #5ba0d0; font-weight: bold;" />
                                </div>
                            </div>
                            <div class="pp-lf-form-row">
                                <div class="pp-lf-form-item">
                                    <label>Header Text Font Size</label>
                                    <select name="formalign" id="pp-lf-headertextfontsize">
                                        <?php fontsize(); ?>
                                    </select>  
                                </div>
                                <div class="pp-lf-form-item">
                                    <label>Supporting Text Font Size</label>
                                    <select name="formalign" id="pp-lf-supportingtextfontsize">
                                        <?php fontsize(); ?>
                                    </select>  
                                </div>
                            </div> 
                            <div class="pp-lf-form-row">
                                <div class="pp-lf-form-item">
                                    <label>Header Text Color</label>
                                    <div name="headertextfontcolor" data-type="slider" id="pp-lf-headertextfontcolorpicker" class="colorpicker"></div>
                                    <input type="text" id="pp-lf-headertextfontcolor" style="border: 0; color: #5ba0d0; font-weight: bold;" data-default-color="#333333" />
                                </div>
                                <div class="pp-lf-form-item">
                                    <label>Supporting Text Color</label>
                                    <div name="supportingtextfontcolor" data-type="slider" id="pp-lf-supportingtextfontcolorpicker" class="colorpicker"></div>
                                    <input type="text" id="pp-lf-supportingtextfontcolor" style="border: 0; color: #5ba0d0; font-weight: bold;" data-default-color="#333333" />
                                </div>
                            </div>
                        </div>
                        <div class="formstylingoptions" id="pp-lf-tabs-4">
                            <div class="pp-lf-form-row">
                                <div class="pp-lf-form-item">
                                    <label>Input Background Color</label>
                                    <div name="inputcolor" data-type="slider" id="pp-lf-inputcolorpicker" class="colorpicker"></div>
                                    <input type="text" id="pp-lf-inputcolor" style="border: 0; color: #5ba0d0; font-weight: bold;" data-default-color="#333333" />
                                </div>
                                <div class="pp-lf-form-item">
                                    <label>Input Text Color</label>
                                    <div name="inputtextcolor" data-type="slider" id="pp-lf-inputtextcolorpicker" class="colorpicker"></div>
                                    <input type="text" id="pp-lf-inputtextcolor" style="border: 0; color: #5ba0d0; font-weight: bold;" data-default-color="#333333" />
                                </div>
                            </div>
                            <div class="pp-lf-form-row">
                                <div class="pp-lf-form-item">
                                    <label>Input Border Color</label>
                                    <div name="inputbordercolor" data-type="slider" id="pp-lf-inputbordercolorpicker" class="colorpicker"></div>
                                    <input type="text" id="pp-lf-inputbordercolor" style="border: 0; color: #5ba0d0; font-weight: bold;" data-default-color="#333333" />
                                </div>
                                <div class="pp-lf-form-item">
                                    <label>Input Field Size</label>
                                    <select name="formalign" id="pp-lf-inputfieldsize">
                                        <option value="">Please Select</option>
                                        <option value="small">Small</option>
                                        <option value="medium">Medium</option>
                                        <option value="large">Large</option>
                                    </select>  
                                </div>
                            </div>
                        </div>
                        <div class="buttonoptions" id="pp-lf-tabs-5">
                            <div class="pp-lf-form-row">
                                <div class="pp-lf-form-item">
                                    <label>Button Size</label>
                                    <select name="formalign" id="pp-lf-buttonsize">
                                        <option value="">Please Select</option>
                                        <option value="small">Small</option>
                                        <option value="medium">Medium</option>
                                        <option value="large">Large</option>
                                        <option value="extralarge">Extra Large</option>
                                    </select>  
                                </div>
                                <div class="pp-lf-form-item">
                                    <label>Button Font Size</label>
                                    <select name="formalign" id="pp-lf-buttonfontsize">
                                        <?php fontsize(); ?>
                                    </select>  
                                </div>
                            </div>
                            <div class="pp-lf-form-row">
                                <div class="pp-lf-form-item">
                                    <label>Button Background Color</label>
                                    <div name="buttonbgcolor" data-type="slider" id="pp-lf-buttonbgcolorpicker" class="colorpicker"></div>
                                    <input type="text" id="pp-lf-buttonbgcolor" style="border: 0; color: #5ba0d0; font-weight: bold;" data-default-color="#333333" />
                                </div>
                                <div class="pp-lf-form-item">
                                    <label>Button Text Color</label>
                                    <div name="buttontextcolor" data-type="slider" id="pp-lf-buttontextcolorpicker" class="colorpicker"></div>
                                    <input type="text" id="pp-lf-buttontextcolor" style="border: 0; color: #5ba0d0; font-weight: bold;" data-default-color="#333333" />
                                </div>
                            </div>
                            <div class="pp-lf-form-row">
                                <div class="pp-lf-form-item">
                                    <label>Button Border Color</label>
                                    <div name="buttonbordercolor" data-type="slider" id="pp-lf-buttonbordercolorpicker" class="colorpicker"></div>
                                    <input type="text" id="pp-lf-buttonbordercolor" style="border: 0; color: #5ba0d0; font-weight: bold;" data-default-color="#333333" />
                                </div>
                                <div class="pp-lf-form-item">
                                    <label>Button Font</label>
                                    <input type="text" id="pp-lf-buttonfont" class="pp-lf-textinput" style="border: 0; color: #5ba0d0; font-weight: bold;" />
                                </div>
                            </div>
                        </div>
                        <div class="buttonhoveroptions" id="pp-lf-tabs-6">
                            <div class="pp-lf-form-row">
                                <div class="pp-lf-form-item">
                                    <label>Button Background Color</label>
                                    <div name="buttonhoverbgcolor" data-type="slider" id="pp-lf-buttonhoverbgcolorpicker" class="colorpicker"></div>
                                    <input type="text" id="pp-lf-buttonhoverbgcolor" style="border: 0; color: #5ba0d0; font-weight: bold;" data-default-color="#333333" />
                                </div>
                                <div class="pp-lf-form-item">
                                    <label>Button Text Color</label>
                                    <div name="buttonhovertextcolor" data-type="slider" id="pp-lf-buttonhovertextcolorpicker" class="colorpicker"></div>
                                    <input type="text" id="pp-lf-buttonhovertextcolor" style="border: 0; color: #5ba0d0; font-weight: bold;" data-default-color="#333333" />
                                </div>
                            </div>
                            <div class="pp-lf-form-row">
                                <div class="pp-lf-form-item">
                                    <label>Button Border Color</label>
                                    <div name="buttonhoverbordercolor" data-type="slider" id="pp-lf-buttonhoverbordercolorpicker" class="colorpicker"></div>
                                    <input type="text" id="pp-lf-buttonhoverbordercolor" style="border: 0; color: #5ba0d0; font-weight: bold;" data-default-color="#333333" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <input type="submit" id="pp-lf-submit" value="Add my form!" style="display: none;" />
            </div>
        </div>
    </div>
</div>
<script type="text/javascript">
// Responsible for removing the overlayed form
jQuery("#pp-lf-overlay").click(function() 
{
    jQuery(".pp-lf-form").hide();
    jQuery('.pp-lf-defaultoptions, .pp-lf-default, .pp-lf-advanced').show();
    jQuery('.pp-lf-advancedoptions, #pp-lf-submit').hide();
});

// Responsible for collecting the form data and push it back to TinyMCE
jQuery("#pp-lf-submit").click(function()
{
    var ids = [];
    var values = [];
    jQuery('.pp-lf-form-row input, .pp-lf-form-row select').each( function(index, value) 
    {
        var id = jQuery(this).attr('id');
        var id = id.substring(6);

        ids.push(id);
        values.push(jQuery(this).val());
    });

    var obj = {};
    jQuery.each(ids, function(i) 
    {
        obj[ids[i]] = values[i];
    });

    var shortcodes = [];
    for(var key in obj) 
    {
        if (obj[key] != '' && obj[key] != '0px' && obj[key] != 'Please Select')
        {
            shortcodes.push(" "+key+"='"+obj[key]+"'");
        }
    }

    tinyMCE.execCommand("mceInsertContent", false, "[login_page"+shortcodes.join('')+"]");
    jQuery(".pp-lf-form").hide();
    return false;
});

// Interface adjustments based upon user selection
jQuery('#pp-lf-style').change(function()
{
   if ( jQuery(this).val() == 'fullwidth' )
   {
        jQuery('.widthalign').hide(400);
   } 
   else
   {
        jQuery('.widthalign').show(400);
   }
});
</script>