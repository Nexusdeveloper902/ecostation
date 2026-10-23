package co.ecostation.app.store

/**
 * The four persisted flags of the one-time link hand-off, read and written as
 * one value so a process kill cannot leave `linkPending` and its deadline
 * disagreeing. UI flags only, never secrets.
 */
data class LinkFlags(
    val pending: Boolean = false,
    val pendingUntilMs: Long = 0L,
    val sent: Boolean = false,
    val sentAtMs: Long = 0L,
) {
    companion object {
        val NONE = LinkFlags()
    }
}
