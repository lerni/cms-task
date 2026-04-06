<div class="background-task-field"
     data-url-start="$StartLink"
     data-url-stop="$StopLink"
     data-url-stream="$StreamBaseURL"
     data-task-name="$TaskCommandName"
     data-security-id="$SecurityID"
     <% if $ActiveTaskId %>data-task-id="$ActiveTaskId"<% end_if %>>

    <div class="background-task-field__header">
        <span class="background-task-field__title">$Title</span>
        <span class="background-task-field__status" data-status="idle">Idle</span>
    </div>

    <div class="background-task-field__controls">
        <button type="button" class="btn btn-primary btn-sm background-task-field__start">
            <%t Kraftausdruck\Fields\BackgroundTaskField.START 'Start Task' %>
        </button>
        <button type="button" class="btn btn-outline-danger btn-sm background-task-field__stop" hidden>
            <%t Kraftausdruck\Fields\BackgroundTaskField.STOP 'Stop' %>
        </button>
    </div>

    <div class="background-task-field__progress" hidden>
        <div class="background-task-field__progress-track">
            <div class="background-task-field__progress-bar" style="width: 0%"></div>
        </div>
        <span class="background-task-field__progress-text">0%</span>
    </div>

    <div class="background-task-field__log" hidden>
        <div class="background-task-field__log-content"></div>
    </div>
</div>
