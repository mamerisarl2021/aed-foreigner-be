<?php

namespace App\Enums;

enum NotificationTemplate: string
{
    case UserAddedToAed = 'USER_ADDED_TO_AED';
    case AgentAddedToAed = 'AGENT_ADDED_TO_AED';
    case ResetLink = 'RESET_LINK';
    case SendOtp = 'SEND_OTP';
    case SendLink = 'SEND_LINK';
    case SendInitLink = 'SEND_INIT_LINK';
    case ForeignerOtp = 'FOREIGNER_OTP';
    case ForeignerFinalized = 'FOREIGNER_FINALIZED';
    case IdentityStepApproved = 'IDENTITY_STEP_APPROVED';
    case IdentityRejected = 'IDENTITY_REJECTED';
    case EnrollmentVisioRequested = 'ENROLLMENT_VISIO_REQUESTED';
    case EnrollmentReturnedToAgent = 'ENROLLMENT_RETURNED_TO_AGENT';
    case EnrollmentSlaAlert = 'ENROLLMENT_SLA_ALERT';
}
