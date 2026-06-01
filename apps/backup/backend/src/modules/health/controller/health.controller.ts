import type { ApiResponse } from '@corejs/shared/types';
import { Injectable } from '@nestjs/common';
import { HealthDto } from '../modelo/health.dto';

@Injectable()
export class HealthController {
  getHealth(): ApiResponse<HealthDto> {
    return {
      ok: true,
      data: {
        service: 'corejs-backend',
        status: 'ok',
      },
    };
  }
}

