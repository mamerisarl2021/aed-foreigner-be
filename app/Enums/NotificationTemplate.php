<?php

namespace App\Enums;

enum NotificationTemplate: string
{
    case UserAddedToAed = 'USER_ADDED_TO_AED';
    case AgentAddedToAed = 'AGENT_ADDED_TO_AED';
    case ResetLink = 'RESET_LINK';
    case SendOtp = 'SEND_OTP';
    case NotifyAdmin = 'NOTIFY_ADMIN';
    case SendLink = 'SEND_LINK';
    case SendInitLink = 'SEND_INIT_LINK';
    case UserSubscriptionCreated = 'USER_SUBSCRIPTION_CREATED';
    case UserSubscriptionInitiated = 'USER_SUBSCRIPTION_INITIATED';
    case UserSubscriptionValidated = 'USER_SUBSCRIPTION_VALIDATED';
    case AdvancedIdRequest = 'ADVANCED_ID_REQUEST';
    case AdvancedIdMid = 'ADVANCED_ID_MID';
    case Planned = 'PLANNED';
    case Scheduled = 'SCHEDULED';
    case Rescheduled = 'RESCHEDULED';
    case Revocated = 'REVOCATED';
    case ForeignerOtp = 'FOREIGNER_OTP';
    case ForeignerInitRegistration = 'FOREIGNER_INIT_REGISTRATION';
    case ForeignerFinalized = 'FOREIGNER_FINALIZED';
    case IdentityStepApproved = 'IDENTITY_STEP_APPROVED';
    case IdentityRejected = 'IDENTITY_REJECTED';
    case EnrollmentVisioRequested = 'ENROLLMENT_VISIO_REQUESTED';
    case EnrollmentReturnedToAgent = 'ENROLLMENT_RETURNED_TO_AGENT';
    case EnrollmentSlaAlert = 'ENROLLMENT_SLA_ALERT';
    case StructureInvitation = 'STRUCTURE_INVITATION';
    case SignatureInvitation = 'SIGNATURE_INVITATION';
    case DocumentSigned = 'DOCUMENT_SIGNED';
    case SignatureRejected = 'SIGNATURE_REJECTED';
}
