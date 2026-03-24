namespace PatchAgent.Service.Models;

public sealed class JobCompletionReport
{
    public string FinalState { get; set; } = "Succeeded";

    public string InstallResult { get; set; } = "success";

    public bool RebootRequired { get; set; }

    public bool RebootPerformed { get; set; }

    public string PostRebootValidation { get; set; } = "not_run";

    public string? ErrorCode { get; set; }

    public string? ErrorMessage { get; set; }

    public bool? Retryable { get; set; }
}
