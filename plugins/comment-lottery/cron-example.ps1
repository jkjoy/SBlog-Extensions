param(
    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string]$WorkerUrl,

    [Parameter(Mandatory = $true)]
    [ValidateNotNullOrEmpty()]
    [string]$WorkerKey
)

$ErrorActionPreference = 'Stop'
$lotteryEndpoint = $null
if (-not [Uri]::TryCreate($WorkerUrl, [UriKind]::Absolute, [ref]$lotteryEndpoint) -or
    $lotteryEndpoint.Scheme -notin @('http', 'https')) {
    throw 'WorkerUrl must be an absolute HTTP or HTTPS URL.'
}

# This script runs the worker once. Configure repetition in Task Scheduler.
# The key is sent in a header, never in the URL or command output.
$lotteryResponse = Invoke-RestMethod -Uri $lotteryEndpoint.AbsoluteUri -Method Post `
    -Headers @{ 'X-SBlog-Lottery-Key' = $WorkerKey } -TimeoutSec 180

if ($null -ne $lotteryResponse -and $lotteryResponse.ok -eq $false) {
    throw 'The lottery worker returned an unsuccessful response.'
}
$lotteryResponse
