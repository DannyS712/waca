{* If custom create reasons are active, then make the Created button a split button dropdown. *}
<form method="post" class="form-compact" action="{$baseurl}/internal.php/viewRequest/close">
    <a class="btn btn-primary span6" target="_blank"
       href="{$mediawikiScriptPath}?title=Special:UserLogin/signup&amp;wpName={$requestName|escape:'url'}&amp;email={$requestEmail|escape:'url'}&amp;reason={$createAccountReason|escape:'url'}{$requestId}&amp;wpCreateaccountMail=true"
            {if !$currentUser->getAbortPref() && $createdHasJsQuestion} onclick="return confirm('{$createdJsQuestion}')"{/if}>
        Create account
    </a>
    {if !empty($createReasons)}
        <div class="btn-group span6">
            <button class="btn btn-success span10" type="submit" name="template" value="{$createdId}">
                {$createdName|escape}
            </button>

            <button type="button"
                    class="btn btn-success dropdown-toggle span2"
                    data-toggle="dropdown">&nbsp;<span class="caret"></span></button>

            <ul class="dropdown-menu" role="menu">
                {foreach $createReasons as $reason}
                    <li>
                        <button class="btn-link" name="template" value="{$reason->getId()}" type="submit"
                                {if !$currentUser->getAbortPref() && $reason->getJsquestion() != ''}
                            onclick="return confirm('{$reason->getJsquestion()|escape}')"
                                {/if}>
                            {$reason->getName()|escape}
                        </button>
                    </li>
                {/foreach}
            </ul>
        </div>
    {else}
        <div class="span6">
            <button class="btn btn-success span12" type="submit" name="template" value="{$createdId}">
                {$createdName|escape}
            </button>
        </div>
    {/if}
    <input type="hidden" name="request" value="{$requestId}"/>
    <input type="hidden" name="updateversion" value="{$updateVersion}"/>
    {include file="security/csrf.tpl"}
</form>
